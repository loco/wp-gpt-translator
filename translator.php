<?php
/**
 * Hook fired as a filter for the "gpt" translation api.
 *
 * @param string[] $sources Input strings
 * @param Loco_Locale $locale Target locale for translations
 * @param array $config API configuration hooked via `loco_api_providers`
 * @return string[] output strings
 * @throws Loco_error_Exception
 */
function loco_gpt_process_batch( array $sources, Loco_Locale $locale, array $config ) {
    
    // Switch GPT model: See https://platform.openai.com/docs/models/overview
    if( ! array_key_exists('model',$config) ){
        $config['model'] = 'gpt-3.5-turbo';
    }

    // GPT wants a wordy language name. We'll handle this with our own data.
    $config['sourceLang'] = 'English';
    $config['targetLang'] = loco_gpt_wordy_language($locale);
    
    // source language may be overridden by `loco_api_provider_source` hook
    $tag = Loco_mvc_PostParams::get()['source'];
    if( is_string($tag) && '' !== $tag ){
        $locale = Loco_Locale::parse($tag);
        if( $locale->isValid() ){
            $config['sourceLang']  = loco_gpt_wordy_language($locale);
        }
    }

    // done with locale data. free up memory.
    Loco_data_CompiledData:flush();
    
    // See "Chat vs completions": https://platform.openai.com/docs/guides/chat/chat-vs-completions
    if( preg_match('/^gpt-3\\.5/',$config['model']) ){
        return loco_gpt_translate_via_chat( $sources, $config );
    }
    // Default to old completions api with 3.x model... nope.
    // return loco_gpt_translate_via_completion( $sources, $config );
    throw new Loco_error_Exception('GPT 3.5 models only');
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
 * @return array
 */
function loco_gpt_init_request_arguments( array $config, array $data ){
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
        'body' => json_encode( $data ),
    ];
}


/**
 * @internal
 * @param WP_Error|array $result
 * @return array
 */
function loco_gpt_decode_response( $result ){
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
    return $data;
}


/**
 * Translate vi GPT Chat API, so we can use GPT 3.5
 * See https://platform.openai.com/docs/guides/chat
 * @internal
 * @return array
 */
function loco_gpt_translate_via_chat( array $sources, array $config ){

    // Longer cURL timeout when testing. Free accounts will get timeouts during busy times
    add_filter('http_request_timeout',function(){ return 10; });

    // https://platform.openai.com/docs/api-reference/chat/create
    $result = wp_remote_request( 'https://api.openai.com/v1/chat/completions', loco_gpt_init_request_arguments( $config, [
        'model' => $config['model'],
        'temperature' => 0,
        'messages' => [
            [ 'role' => 'system', 'content' => 'You are a helpful assistant that translates '.$config['sourceLang'].' to '.$config['targetLang'].' and replies with well formed JSON arrays only' ],
            [ 'role' => 'user',   'content' => 'Translate the following JSON array from '.$config['sourceLang'].' to a JSON array in '.$config['targetLang']." even if the values are the same:\n".json_encode($sources) ],
        ],
    ]) );

    $data = loco_gpt_decode_response($result);
    $json = $data['choices'][0]['message']['content'];
    $data = json_decode( $json, true, 2 );
    if( ! is_array($data) ){
        Loco_error_Debug::trace($json);
        throw new Loco_error_Exception('GPT Chat assistant did not reply with a JSON array');
    }
    return $data;
}
