<?php

set_time_limit(300);

require_once 'vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Pull out variables from .env or $_ENV
function env($name, $default = null): ?string {
    return $_ENV[$name] ?? $default;
}

// Delete an entire directory and its contents, recursively
function deleteTree($dir): bool {
    if (!file_exists($dir)) return true;

    // Remove read-only and hidden attributes on Windows
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        @shell_exec('attrib -R -H "' . $dir . '" /S /D');
    }

    if (is_file($dir) || is_link($dir)) {
        return @unlink($dir);
    }

    $files = array_diff(scandir($dir), array('.', '..'));

    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;

        // remove read-only and hidden attributes on Windows
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            @shell_exec('attrib -R -H "' . $path . '" /S /D');
        }
        if (is_dir($path)) {
            deleteTree($path);
        } else {
            @unlink($path);
        }
    }

    return @rmdir($dir);
}

// Log function for debugging
function note($message, $level = 'INFO') {
    $log = "[$level] " . date('Y-m-d H:i:s') . " - $message\n";
    file_put_contents(__DIR__ . '/local.log', $log, FILE_APPEND);
    file_put_contents('php://stdout', $log);
    echo $log;
}

// Main entry point - handles the incoming webhook
function handleWebhook() {
    
    if (!validateGitHubWebhook(env('GITHUB_WEBHOOK_SECRET'))) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
    
    $payload = json_decode(file_get_contents('php://input'), true);
    
    processIssue($payload);
    
    http_response_code(200);
    echo json_encode(['status' => 'Webhook processed']);
    exit;
}

// Validate that the webhook is actually from GitHub
function validateGitHubWebhook($secret) {
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    $payload = file_get_contents('php://input');
    
    if (empty($signature) || empty($payload)) {
        return false;
    }
    
    $computedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

    return hash_equals($computedSignature, $signature);
}

// Process an issue and create a PR with changes
function processIssue($payload) {
    
    $repoOwner = $payload['repository']['owner']['login'];
    $repoName = $payload['repository']['name'];
    $issueNumber = $payload['issue']['number'];
    $issueTitle = $payload['issue']['title'];
    $issueBody = $payload['issue']['body'];
    
    $branchName = 'fix-for-issue-' . $issueNumber;
    
    $repoPath = cloneRepository($repoOwner, $repoName, env('GITHUB_TOKEN'), env('TEMP_DIR'), env('OS_TYPE'));
    
    createBranch($repoPath, $branchName);
    
    $codeContext = scanCodebase($repoPath);

    $llmResponse = sendToLLM($codeContext, $issueTitle, $issueBody, env('OPENAI_API_KEY'), env('OPENAI_MODEL'), env('LLM_PROVIDER'));
    
    applyChanges($repoPath, $llmResponse['changes']);
    
    commitChanges($repoPath, $llmResponse['commit_message']);
    
    pushBranch($repoPath, $branchName, env('GITHUB_TOKEN'));
    
    createPR($repoOwner, $repoName, $branchName, $issueNumber, $llmResponse['pr_description'], env('GITHUB_TOKEN'));
    
    cleanupTempFiles($repoPath);
}

// Clone the repository to a temporary directory
function cloneRepository($owner, $repo, $token, $tempDir, $os): string {
    
    $sep = ($os === 'windows') ? '\\' : '/';
    $tempDir = rtrim($tempDir, '/\\');
    $repoPath = $tempDir . $sep . $owner . $sep . $repo;

    if (file_exists($repoPath)) {
        deleteTree($repoPath);
        // Wait for the directory to be fully deleted
        $wait = 0;
        while (file_exists($repoPath) && $wait < 10) {
            usleep(100000); // 0.1 seconds
            $wait++;
        }
        if (file_exists($repoPath)) {
            throw new Exception("Failed to delete existing repository directory {$repoPath}");
        }
    }
    
    $cloneUrl = "https://{$token}@github.com/{$owner}/{$repo}.git";
    
    $parentDir = dirname($repoPath);
    if (!file_exists($parentDir)) {
        mkdir($parentDir, 0777, true);
    }
    
    if ($os === 'windows') {
        $command = "git clone {$cloneUrl} \"{$repoPath}\" 2>&1";
    } else {
        $command = "git clone {$cloneUrl} {$repoPath} 2>&1";
    }
    
    exec($command, $output, $returnCode);
    
    if ($returnCode !== 0) {
        note("Failed to clone repository: " . implode("\n", $output), 'ERROR');
        throw new Exception("Failed to clone repository {$owner}/{$repo}");
    }
    
    note("Successfully cloned repository {$owner}/{$repo}");
    return $repoPath;
}

// Create a new branch in the repository
function createBranch($repoPath, $branchName): bool {

    chdir($repoPath);
    $command = "git checkout -b {$branchName} 2>&1";
    exec($command, $output, $returnCode);
    
    if ($returnCode !== 0) {
        note("Failed to create branch {$branchName}: " . implode("\n", $output), 'ERROR');
        return false;
    }
    
    note("Successfully created branch {$branchName}");
    return true;
}

// Scan the codebase and create a context file for the LLM
function scanCodebase($repoPath): string|bool {
    $output = '';
    $amount = 0;
    
    $codeExtensions = [
        'php', 'js', 'ts', 'jsx', 'tsx', 'vue', 'css', 'scss', 'html', 'py', 'java', 
        'c', 'cpp', 'h', 'hpp', 'cs', 'go', 'rb', 'swift', 'kt', 'rs'
    ];
    
    $excludeDirs = [
        'vendor', 'node_modules', '.git', 'storage', 'logs', 'tests', 'dist', 'build',
        'coverage', 'cache', 'tmp', 'temp'
    ];
    
    // Maximum file size (5MB)
    $maxFileSize = 5 * 1024 * 1024;
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($repoPath, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        $relativePath = str_replace($repoPath . '/', '', $path);
        
        // Skip excluded directories
        $shouldSkip = false;
        foreach ($excludeDirs as $excludeDir) {
            if (strpos($relativePath, $excludeDir . '/') === 0) {
                $shouldSkip = true;
                break;
            }
        }
        if ($shouldSkip) continue;
        
        // Skip if not a code file
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, $codeExtensions)) continue;
        
        // Skip if file is too large
        if ($file->getSize() > $maxFileSize) {
            note("Skipping large file: {$relativePath}", 'WARNING');
            continue;
        }
        
        try {
            $content = file_get_contents($path);
            if ($content === false) {
                note("Failed to read file: {$relativePath}", 'ERROR');
                continue;
            }
            
            $output .= "FILE: {$relativePath}\n\n";
            $output .= $content;
            $output .= "\n\n\n"; // Add extra newlines between files
            
            $amount++;
        } catch (Exception $e) {
            note("Error processing file {$relativePath}: " . $e->getMessage(), 'ERROR');
        }
    }
    
    note("Added {$amount} files to context window.");

    return $output;
}

// Send the context and issue to the LLM
function sendToLLM($codeContext, $issueTitle, $issueBody, $apiKey, $model, $provider)
{

    $prompt = createLLMPrompt($codeContext, $issueTitle, $issueBody);
    
    return getJSONFromAIProvider($prompt, $apiKey, $model, $provider);
}

// Create a prompt for the LLM
function createLLMPrompt($codeContext, $issueTitle, $issueBody): string {
    return <<<PROMPT
You are an AI coding assistant tasked with helping resolve GitHub issues. You will be provided with:
1. The codebase context
2. The issue title and description

Your task is to analyze the issue and respond with a full list of code changes needed to resolve the issue in its entirety. You must respond in the following JSON format:

{
    "changes": [
        {
            "file": "path/to/file",
            "changes": [
                {
                    "type": "replace|insert|delete",
                    "start_line": number (integer),
                    "end_line": number (integer),
                    "content": "new content to insert/replace"
                }
            ]
        }
    ],
    "commit_message": "A clear, concise commit message describing the changes",
    "pr_description": "A detailed description of the changes made and how they resolve the issue"
}

Rules for changes:
1. For 'replace' type: specify start_line and end_line of the text to replace
2. For 'insert' type: specify start_line where to insert (end_line should be same as start_line)
3. For 'delete' type: specify start_line and end_line of the text to delete
4. Line numbers should be 1-indexed and should ONLY contain whole integers (absolutely no words or placeholder values)
5. The 'content' field is only required for 'replace' and 'insert' types
6. Multiple changes can be specified for each file

Here is the codebase context:

{$codeContext}

Here is the issue to resolve:

Title: {$issueTitle}

Description:
{$issueBody}

Please analyze the issue and provide your response in the specified JSON format.

NOTE: Do not include any additional text besides the JSON specified. Do not include notes above or below the JSON specified. Return ONLY the JSON.
PROMPT;
}

// Call the OpenAI API
function getJSONFromAIProvider($prompt, $apiKey, $model, $provider = 'openai') {
    $client = new \GuzzleHttp\Client();
    
    // Provider-specific configurations
    $configs = [
        'openai' => [
            'url' => 'https://api.openai.com/v1/responses',
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => [
                'model' => $model,
                'input' => [
                    ['role' => 'user', 'content' => $prompt]
                ]
            ]
        ],
        'anthropic' => [
            'url' => 'https://api.anthropic.com/v1/messages',
            'headers' => [
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
            'body' => [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
                'max_tokens' => 1000
            ]
        ]
    ];

    if (!isset($configs[$provider])) {
        throw new \InvalidArgumentException("Unsupported AI provider: {$provider}");
    }

    $config = $configs[$provider];

    try {
        $response = $client->post($config['url'], [
            'headers' => $config['headers'],
            'json' => $config['body'],
            'timeout' => 120,
        ]);

        $result = json_decode($response->getBody()->getContents(), true);
        
        $rawResponse = '';
        switch ($provider) {
            case 'openai':
                $rawResponse = $result['output'][1]['content'][0]['text'] ?? '';
                break;
            case 'anthropic':
                $rawResponse = $result['content'][0]['text'] ?? '';
                break;
            default:
                $rawResponse = '';
        }

        // Clean up the response by removing markdown code block markers
        $rawResponse = preg_replace('/^```json\s*/', '', $rawResponse);
        $rawResponse = preg_replace('/\s*```$/', '', $rawResponse);
        
        // Try to decode the cleaned response
        $decodedResponse = json_decode($rawResponse, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Failed to parse AI response as JSON: " . json_last_error_msg());
        }
        
        return $decodedResponse;
    } catch (\GuzzleHttp\Exception\ClientException $e) {
        // Handle API errors
        $error = json_decode($e->getResponse()->getBody()->getContents(), true);
        throw new \Exception("AI API Error: " . ($error['error']['message'] ?? $e->getMessage()));
    } catch (\Exception $e) {
        // Handle other errors
        throw new \Exception("Error calling AI provider: " . $e->getMessage());
    }
}

// Apply the changes suggested by the LLM
function applyChanges($repoPath, $changes) {
    foreach ($changes as $fileChange) {
        $filePath = $repoPath . '/' . $fileChange['file'];
        
        // Skip if file doesn't exist and we're not inserting
        if (!file_exists($filePath) && $fileChange['changes'][0]['type'] !== 'insert') {
            note("File not found: {$fileChange['file']}", 'ERROR');
            continue;
        }
        
        $lines = file_exists($filePath) ? file($filePath, FILE_IGNORE_NEW_LINES) : [];
        
        foreach ($fileChange['changes'] as $change) {
            switch ($change['type']) {
                case 'replace':
                    $newLines = explode("\n", $change['content']);
                    array_splice($lines, $change['start_line'] - 1, $change['end_line'] - $change['start_line'] + 1, $newLines);
                    break;
                    
                case 'insert':
                    $newLines = explode("\n", $change['content']);
                    array_splice($lines, $change['start_line'] - 1, 0, $newLines);
                    break;
                    
                case 'delete':
                    array_splice($lines, $change['start_line'] - 1, $change['end_line'] - $change['start_line'] + 1);
                    break;
                    
                default:
                    note("Unknown change type: {$change['type']}", 'ERROR');
                    continue 2;
            }
        }
        
        // Write the modified content back to the file
        // Create directory structure if it doesn't exist
        $directory = dirname($filePath);
        if (!file_exists($directory)) {
            if (!mkdir($directory, 0777, true)) {
                note("Failed to create directory: {$directory}", 'ERROR');
                return false;
            }
        }
        
        if (!file_put_contents($filePath, implode("\n", $lines) . "\n")) {
            note("Failed to write changes to file: {$fileChange['file']}", 'ERROR');
            return false;
        }
        
        note("Successfully applied changes to: {$fileChange['file']}");
    }
    
    return true;
}

// Commit the changes to the repository
function commitChanges($repoPath, $commitMessage) {

    chdir($repoPath);
    
    $stageCommand = "git add . 2>&1";
    exec($stageCommand, $stageOutput, $stageReturnCode);
    
    if ($stageReturnCode !== 0) {
        note("Failed to stage changes: " . implode("\n", $stageOutput), 'ERROR');
        return false;
    }
    
    $commitCommand = "git commit -m " . escapeshellarg($commitMessage) . " 2>&1";
    exec($commitCommand, $commitOutput, $commitReturnCode);
    
    if ($commitReturnCode !== 0) {
        note("Failed to commit changes: " . implode("\n", $commitOutput), 'ERROR');
        return false;
    }
    
    note("Successfully committed changes with message: {$commitMessage}");
    return true;
}

// Push the branch to GitHub
function pushBranch($repoPath, $branchName, $token) {

    chdir($repoPath);
    
    $remoteUrl = trim(shell_exec('git config --get remote.origin.url'));
    
    $remoteUrl = preg_replace(
        '#https://([^@]+@)?github.com/#',
        "https://{$token}@github.com/",
        $remoteUrl
    );
    
    $command = "git push \"{$remoteUrl}\" {$branchName} 2>&1";
    
    exec($command, $output, $returnCode);
    
    if ($returnCode !== 0) {
        note("Failed to push branch {$branchName}: " . implode("\n", $output), 'ERROR');
        return false;
    }
    
    note("Successfully pushed branch {$branchName}");
    return true;
}

// Create a PR on GitHub
function createPR($owner, $repo, $branchName, $issueNumber, $description, $token) {
    $client = new \GuzzleHttp\Client();
    
    try {
        $response = $client->post("https://api.github.com/repos/{$owner}/{$repo}/pulls", [
            'headers' => [
                'Authorization' => 'token ' . $token,
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'PHP-GitHub-Webhook'
            ],
            'json' => [
                'title' => "Fix for issue #{$issueNumber}",
                'body' => $description . "\n\nCloses #{$issueNumber}",
                'head' => $branchName,
                'base' => 'main'
            ]
        ]);
        
        $prData = json_decode($response->getBody()->getContents(), true);
        $prNumber = $prData['number'];
        
        note("Successfully created PR #{$prNumber}");
        return $prNumber;
        
    } catch (\GuzzleHttp\Exception\ClientException $e) {
        $error = json_decode($e->getResponse()->getBody()->getContents(), true);
        note("Failed to create PR: " . ($error['message'] ?? $e->getMessage()), 'ERROR');
        return false;
    } catch (\Exception $e) {
        note("Error creating PR: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// Clean up temporary files
function cleanupTempFiles($repoPath) {
    if (file_exists($repoPath)) {
        try {
            $result = deleteTree($repoPath);
            note("Successfully cleaned up temporary files at: {$repoPath}");
            return $result;
        } catch (Exception $e) {
            note("Failed to clean up temporary files: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    return true;
}

// Start the application
handleWebhook();