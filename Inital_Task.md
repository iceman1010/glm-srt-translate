try to port the following application:
reference_project/cf-llm-srt-translator/

it needs to use another service provider, not Cloudflare. The new service provider is Z.AI.
Here you can find documentation how to access the API:

https://docs.z.ai/api-reference/llm/chat-completion.md


API key for this project:
.env

main difference between the original app you need to port from is that this one uses different model provider, while these ones are only the models offered by Z.AI

Please try to use the same prompt logic and the same way of processing the LLM driven subtitle translation.
