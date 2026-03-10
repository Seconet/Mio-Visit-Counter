<?php
/**
 * Plugin Name: Mio Visit Counter
 * Plugin URI: https://seconet.it
 * Description: Contatore visite semplice e senza pubblicità
 * Version: 1.0.0
 * Author: Sergio Cornacchione
 * License: GPL v2 or later
 */


// Previeni accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

class MioVisitCounter {
    
    public function __construct() {
        // Incrementa visite all'apertura del post
        add_action('wp', [$this, 'count_visit']);
        
        // Shortcode per mostrare il contatore
        add_shortcode('visite', [$this, 'display_counter']);
        
        // Meta box nell'admin
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        
        // AJAX per reset
        add_action('wp_ajax_svc_reset_counter', [$this, 'ajax_reset_counter']);
    }
    
    /**
     * Conta le visite (con tutti i controlli necessari)
     */
    public function count_visit() {
        // ✅ 1. Controlla che sia un singolo post
        if (!is_singular('post')) {
            return;
        }
        
        $post_id = get_the_ID();
        
        // ✅ 2. Controlla che il post esista
        if (!$post_id) {
            return;
        }
        
        // ✅ 3. Evita di contare nelle anteprime
        if (is_preview()) {
            return;
        }
        
        // ✅ 4. Evita di contare per admin/editor (opzionale)
        if (current_user_can('edit_posts')) {
            return;
        }
        
        // ✅ 5. Evita conteggi multipli nella stessa sessione
        $session_key = 'svc_visited_' . $post_id;
        if (!session_id()) {
            session_start();
        }
        
        if (isset($_SESSION[$session_key])) {
            return;
        }
        
        // ✅ 6. Evita bot e crawler (controllo base user agent)
        if ($this->is_bot()) {
            return;
        }
        
        // ✅ 7. LEGGI il valore attuale (con valore di default 0)
        $visite_attuali = (int) get_post_meta($post_id, '_svc_visite', true);
        
        // ✅ 8. Incrementa e salva
        $nuove_visite = $visite_attuali + 1;
        update_post_meta($post_id, '_svc_visite', $nuove_visite);
        
        // ✅ 9. Segna come visitato in questa sessione
        $_SESSION[$session_key] = true;
    }
    
    /**
     * Rilevamento base dei bot
     */
    private function is_bot() {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $bots = ['bot', 'crawler', 'spider', 'scraper', 'curl', 'wget', 'python'];
        
        foreach ($bots as $bot) {
            if (stripos($user_agent, $bot) !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Shortcode per mostrare il contatore
     */
    public function display_counter($atts) {
        $atts = shortcode_atts([
            'testo' => '👁️ Visualizzazioni:',
            'post_id' => get_the_ID(),
            'mostra_solo_se' => 0,
            'nessun_dato' => 'Ancora nessuna visualizzazione'
        ], $atts);
        
        $visite = (int) get_post_meta($atts['post_id'], '_svc_visite', true);
        
        if ($visite < $atts['mostra_solo_se']) {
            return '';
        }
        
        if ($visite === 0) {
            return sprintf('<span class="svc-counter">%s</span>', esc_html($atts['nessun_dato']));
        }
        
        return sprintf(
            '<span class="svc-counter">%s %s</span>',
            esc_html($atts['testo']),
            number_format($visite, 0, '', '.')
        );
    }
    
    /**
     * Meta box nell'editor
     */
    public function add_meta_box() {
        add_meta_box(
            'svc_metabox',
            '📊 Statistiche Visite',
            [$this, 'render_meta_box'],
            'post',
            'side',
            'high'
        );
    }
    
    /**
     * Contenuto del meta box
     */
    public function render_meta_box($post) {
        $visite = (int) get_post_meta($post->ID, '_svc_visite', true);
        $nonce = wp_create_nonce('svc_reset_' . $post->ID);
        
        echo '<p style="font-size: 24px; text-align: center; margin: 10px 0;">' . 
             number_format($visite, 0, '', '.') . 
             '</p>';
        
        // Bottone reset (solo per admin)
        if (current_user_can('manage_options')) {
            echo '<button type="button" class="button reset-visite" style="width: 100%;" data-post="' . 
                 $post->ID . '" data-nonce="' . $nonce . '">🔄 Reset contatore</button>';
            
            $this->reset_script();
        }
    }
    
    /**
     * Script AJAX per reset
     */
    private function reset_script() {
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('.reset-visite').on('click', function() {
                if (!confirm('Sei sicuro di voler resettare il contatore a zero?')) return;
                
                var button = $(this);
                var data = {
                    action: 'svc_reset_counter',
                    post_id: button.data('post'),
                    nonce: button.data('nonce')
                };
                
                $.post(ajaxurl, data, function(response) {
                    if (response.success) {
                        button.siblings('p').text('0');
                        button.text('✅ Resettato!').prop('disabled', true);
                        setTimeout(function() {
                            button.text('🔄 Reset contatore').prop('disabled', false);
                        }, 2000);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX handler per reset
     */
    public function ajax_reset_counter() {
        // Verifica nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'svc_reset_' . $_POST['post_id'])) {
            wp_send_json_error('Nonce non valido');
        }
        
        // Verifica permessi
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Non autorizzato');
        }
        
        // Verifica post_id
        $post_id = intval($_POST['post_id']);
        if (!$post_id) {
            wp_send_json_error('ID post non valido');
        }
        
        // Reset contatore
        update_post_meta($post_id, '_svc_visite', 0);
        
        wp_send_json_success('Contatore resettato');
    }
}

// Avvia il plugin
new MioVisitCounter();