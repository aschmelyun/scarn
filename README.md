# Agent Scarn

A proof-of-concept "asynchronous coding agent" built as a single PHP script. [See video](https://www.youtube.com/watch?v=rSfIOyQASC4)

This was created as a fun experiment to see if I could make something like Devin, Jules, or Copilot Agent. Obviously it's much worse so I wouldn't recommend using this in practice, but it _does_ function as intended most of the time!

This program goes through a fairly basic set of instructions:

1. Listen for webhook events from a GitHub issue created
2. Checkout the associated repo
3. Compiles all source code and issue information into a single large context window
4. Sends that context to an LLM provider (OpenAI, Anthropic, etc)
5. Formats and filters the JSON output generated
6. Performs changes to those files based on the generated output
7. Commits the changes to a new branch and opens up a PR in GitHub

If you'd like to run this yourself, you can start it with PHP's built-in webserver

```bash
php -S 127.0.0.1:8000 app.php
```

and expose the `:8000` port to a public URL with Ngrok, Cloudflare Tunnels, or some other similar service.

Then, add in a webhook with the exposed URL to the GitHub repo(s) of your choice.

> [!NOTE]
> If you're feeling extra brave, you can deploy this out on a public webserver. (do not recommend lol)

Going forward it would be interesting to see if I could implement a more full agentic loop. For example, having the LLM generate tests based on the issue provided and continuously loop through code changes until the tests run green, only then making the commit and PR.
