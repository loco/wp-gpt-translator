<?php
/**
 * Hook fired as a filter for the "gpt" translation api.
 *
 * @param string[] $targets translated strings, initially empty
 * @param string[][] $items input messages with keys, "source", "context" and "notes"
 * @param Loco_Locale $locale target locale for translations
 * @param array $config This api's configuration
 * @return string[] Translated strings
 * @throws Loco_error_Exception
 */
function loco_gpt_process_batch( array $targets, array $items, Loco_Locale $locale, array $config ) {
    
    // Switch GPT model: See https://platform.openai.com/docs/models/model-endpoint-compatibility
    if( ! array_key_exists('model',$config) ){
        $config['model'] = 'gpt-4o-mini';
    }

    // GPT wants a wordy language name. We'll handle this with our own data.
    $sourceLang = 'English';
    $targetLang = loco_gpt_wordy_language($locale);
    
    // source language may be overridden by `loco_api_provider_source` hook
    $tag = Loco_mvc_PostParams::get()['source'];
    if( is_string($tag) && '' !== $tag ){
        $locale = Loco_Locale::parse($tag);
        if( $locale->isValid() ){
            $sourceLang  = loco_gpt_wordy_language($locale);
        }
    }

    // done with locale data. free up memory.
    Loco_data_CompiledData:flush();

    // Build specific prompt for this batch
    $prompt = 'Translate the `source` properties of the following JSON objects, using the `context` and `notes` properties to identify the meaning';
    // TODO Append more language specific data, like region and formality/tone
    // $prompt.= '. Use the '.$config['tone'].' style';
    // Allow custom prompt via filter for this locale, but protecting our base prompt
    $custom = apply_filters( 'loco_gpt_prompt', '', $locale );
    if( '' !== $custom && is_string($custom) ){
        $prompt .= '. '.$custom;
    }

    // Longer cURL timeout when testing. Free accounts will get timeouts during busy times
    add_filter('http_request_timeout',function(){ return 10; });

    // https://platform.openai.com/docs/api-reference/chat/create
    $result = wp_remote_request( 'https://api.openai.com/v1/chat/completions', loco_gpt_init_request_arguments( $config, [
        'model' => $config['model'],
        'temperature' => 0,
        // Start with our base prompt, adding user instruction at [1] and data at [2]
        'messages' => [
            [ 'role' => 'system', 'content' => 'You are a helpful assistant that translates from '.$sourceLang.' to '.$targetLang ],
            [ 'role' => 'user', 'content' => rtrim($prompt,':.;, ').':' ],
            [ 'role' => 'user', 'content' => json_encode($items,JSON_UNESCAPED_UNICODE) ],
        ],
        // Define schema for reliable returning of correct data
        // https://openai.com/index/introducing-structured-outputs-in-the-api/
        'response_format' => [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'translations_array',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'result' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => [
                                        'type' => 'number',
                                        'description' => 'Corresponding id from the input object'
                                    ],
                                    'text' => [
                                        'type' => 'string',
                                        'description' => 'Translation text of the corresponding input object',
                                    ]
                                ],
                                'required' => ['id','text'],
                                'additionalProperties' => false,
                            ],
                            'description' => 'Translations of the corresponding input array',
                        ],
                    ],
                    'required' => ['result'],
                    'additionalProperties' => false,
                ],
            ],
        ],
    ]) );
    // generic reponse handling
    $data = loco_gpt_decode_response($result);
    // all responses have form {choices:[...]}
    foreach( $data['choices'] as $choice ){
        $blob = $choice['message'] ?? ['role'=>'null'];
        if( isset($blob['refusal']) ){
            Loco_error_Debug::trace('Refusal: %s', $blob['refusal'] );
            continue;
        }
        if( 'assistant' !== $blob['role'] ){
            Loco_error_Debug::trace('Ignoring %s role message', $blob['role'] );
            continue;
        }
        $content = json_decode( trim($blob['content']), true );
        if( ! is_array($content) || ! array_key_exists('result',$content) ){
            Loco_error_Debug::trace("Content doesn't conform to our schema");
            continue;
        }
        $result = $content['result'];
        if( ! is_array($result) || count($result) !== count($items) ){
            Loco_error_Debug::trace("Result array doesn't match our input array");
            continue;
        }
        $i = -1;
        foreach( $result as $r ){
            // object offset should match ID field if json schema is populated correctly
            if( ++$i !== $r['id'] ){
                Loco_error_Debug::trace('Bad id field at [%u] => %s', $i, json_encode($r['id']) );
                continue;
            }
            $translation = $r['text'];
            if( ! is_string($translation) ){
                Loco_error_Debug::trace('Translation at [%u] should be a string => %s', $i, json_encode($translation) );
                continue;
            }
            // Loco_error_Debug::trace('Translated [%u]: %s => %s', $i, $items[$i]['source'], $translation );
            $targets[$i] = $translation;
        }
    }
    return $targets;
}


/**
 * @internal
 * @return string
 */
function loco_gpt_wordy_language( Loco_Locale $locale ){
    $names = Loco_data_CompiledData::get('languages');
    $name = $names[ $locale->lang ];
    // formal, informal etc..
    $tone = $locale->getFormality();
    if( $tone ){
        $name = ucfirst($tone).' '.$name;
    }
    // TODO regional variations, e.g. pt-BR, zh-Hans, etc.. "as spoken in X" ?
    return $name;
}


/**
 * @internal
 */
function loco_gpt_init_request_arguments( array $config, array $data ):array {
    return [
        'method' => 'POST',
        'redirection' => 0,
        'user-agent' => sprintf('Loco Translate/%s; wp-%s', loco_plugin_version(), $GLOBALS['wp_version'] ),
        'reject_unsafe_urls' => false,
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$config['key'],
            'Origin' => $_SERVER['HTTP_ORIGIN'],
            'Referer' => $_SERVER['HTTP_ORIGIN'].'/wp-admin/'
        ],
        'body' => json_encode($data),
    ];
}


/**
 * @internal
 * @param WP_Error|array $result
 */
function loco_gpt_decode_response( $result ):array {
    if( $result instanceof WP_Error ){
        foreach( $result->get_error_messages() as $message ){
            throw new Loco_error_Exception($message);
        }
    }
    // always decode response if server says it's JSON
    if( 'application/json' === substr($result['headers']['Content-Type'],0,16) ){
        $data = json_decode( $result['body'], true );
    }
    else {
        $data = [];
    }
    // TODO handle well formed error messages
    $status = $result['response']['code'];
    if( 200 !== $status ){
        $message = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
        throw new Loco_error_Exception( sprintf('OpenAI API returned status %u: %s',$status,$message) );
    }
    // all responses have form {choices:[...]}
    if( ! is_array($data) || ! array_key_exists('choices',$data) || ! is_array($data['choices']) ){
        throw new Loco_error_Exception('OpenAI API returned unexpected data');
    }
    // 
    return $data;
}


/**
 * Fix for data posted by Loco Translate prior to 2.7.0
 */
function loco_gpt_process_batch_legacy( array $sources, Loco_Locale $locale, array $config ) {
    $items = [];
    foreach( $sources as $text ){
        $items[] = [ 'source' => $text ];
    }
    return loco_gpt_process_batch( [], $items, $locale, $config );
}
