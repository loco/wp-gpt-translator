# ChatGPT translation assistant for Loco Translate

This is an **experimental** add-on for the [Loco Translate WordPress plugin](https://github.com/loco/wp-loco) that attempts to use [ChatGPT](https://platform.openai.com/docs/guides/chat) as a machine translation service.

## Usage

Briefly...

* Install WordPress and [Loco Translate](https://github.com/loco/wp-loco).
* Install this plugin via Git, or by downloading the source.
* Define `OPENAI_API_KEY` somewhere useful, like your WordPress config.
* Try it out from the [Loco Translate editor](https://localise.biz/wordpress/plugin/manual/providers).

## Notes

* If you're a free OpenAI user you'll probably experience very slow API responses during busy times. 
* This plugin currently uses the `gpt-4o-mini` model, but its config can be filtered with `loco_api_provider_gpt`
* Loco is not affiliated with OpenAI.
