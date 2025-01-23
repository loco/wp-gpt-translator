<?php
/*
Plugin Name: ChatGPT Translation assistant for Loco Translate
Plugin URI: https://github.com/loco/wp-gpt-translator
Description: Experimental ChatGPT integration for Loco Translate using OpenAI's chat completions API
Author: Tim Whitlock
Version: 1.1.0
Text Domain: loco-gpt
Author URI: https://localise.biz/wordpress/plugin
*/
if( is_admin() ){
    // Append our api via the `loco_api_providers` filter hook.
    function loco_gpt_filter_apis( array $apis ){
        $apis[] = [
            'id' => 'gpt',
            'name' => 'OpenAI (GPT)',
            'key' => loco_constant('OPENAI_API_KEY'),
            'url' => 'https://openai.com/',
        ];
        return $apis;
    }
    add_filter('loco_api_providers','loco_gpt_filter_apis',10,1);

    // Hook our translate function with 'loco_api_translate_{$id}' where id is "gpt"
    // We only need to do this when the Loco Translate Ajax hook is running.
    function loco_gpt_ajax_init(){
        require __DIR__.'/translator.php';
        // Loco Translate 2.7 started posting more context data
        if( version_compare( loco_plugin_version(), '2.7', '>=' ) ){
            add_filter('loco_api_translate_gpt','loco_gpt_process_batch',0,4);
        }
        else {
            add_filter('loco_api_translate_gpt','loco_gpt_process_batch_legacy',0,3);
        }
    }
    add_action('loco_api_ajax','loco_gpt_ajax_init',0,0);

}
