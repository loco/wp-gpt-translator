# GPT translation assistant for Loco Translate

This is an **experimental** add-on for the [Loco Translate WordPress plugin](https://github.com/loco/wp-loco) that attempts to use [ChatGPT](https://platform.openai.com/docs/guides/chat) as a machine translation service.

## Usage

Briefly...

* Install WordPress and [Loco Translate](https://github.com/loco/wp-loco).
* Install this plugin via Git, or by downloading the source.
* Define `OPENAI_API_KEY` somewhere useful, like your WordPress config.
* Try it out from the [Loco Translate editor](https://localise.biz/wordpress/plugin/manual/providers).

For more detailed instructions, see how to install our [IBM Translation API plugin](https://github.com/loco/wp-ibm-translator).


## About

While it's evident that ChatGPT is very capable of doing translation, OpenAI don't provide a dedicated translation API in the same sense as (for example) DeepL or Google Translate. Interacting with the chat assistant is more like a conversation whereby natural language is sent and received.

Translating a bunch of arbitrary strings makes a chat API a strange fit. I want to send a JSON array of source strings and get a corresponding JSON array back. Fortunately ChatGPT can speak JSON. But does it _want_ to?

The approach used is this...

First the assistant is [prompted](https://platform.openai.com/docs/guides/chat/instructing-chat-models) as follows:

> You are a helpful assistant that translates English to French and replies with well formed JSON arrays only.

Then the source strings are encoded into a JSON array and appended to a prompt, like so:

> Translate the following JSON array from English to a JSON array in French even if the values are the same:  
> ["Foo","Bar","Baz"]

This seems to avoid unpredicatable natural language answers that are unparsable. ChatGPT may be clever, but my code is not.
It seems to oblige and reply with a corresponding array of translations, but your mileage may vary. Feedback welcome.


## Notes

* If you're a free OpenAI user you'll probably experience very slow API responses during busy times. 

* Older GPT 3 models don't work with this plugin. I tried models like `text-davinci-003` but could not get it to behave via the older completions API. See [Chat vs Completions](https://platform.openai.com/docs/guides/chat/chat-vs-completions).

* Loco is not affiliated with OpenAI. 
