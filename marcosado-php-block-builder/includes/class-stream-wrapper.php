<?php
namespace Marcosado\BlockBuilder;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Marcosado_Stream_Wrapper
{
    private $position;
    private $data;

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $slug = wp_parse_url($path, PHP_URL_HOST);
        $slug = sanitize_key($slug);
        
        $cache_key = 'bmcode_' . $slug;
        $code = wp_cache_get($cache_key, 'marcosado_blocks');

        if (false === $code) {
            global $wpdb;
            $code = $wpdb->get_var($wpdb->prepare(
                "SELECT code FROM {$wpdb->prefix}marcosado_blocks WHERE slug = %s",
                $slug
            ));

            $errors = get_option('marcosado_block_errors', []);
            if ($code === null || isset($errors[$slug])) {
                // Failsafe silencieux
                $code = "<?php // Bloqué ;";
            }
            
            wp_cache_set($cache_key, $code, 'marcosado_blocks');
        }

        // Ensure the code starts with <?php if it doesn't
        $code = trim($code);
        if (!str_starts_with($code, '<?php')) {
            $code = "<?php\n" . $code;
        }

        $this->data = $code;
        $this->position = 0;
        return true;
    }

    public function stream_read($count)
    {
        $ret = substr($this->data, $this->position, $count);
        $this->position += strlen($ret);
        return $ret;
    }

    public function stream_eof()
    {
        return $this->position >= strlen($this->data);
    }

    public function stream_stat()
    {
        // Return a valid fake stat to avoid PHP warnings when include() calls stat
        return [
            'dev' => 0,
            'ino' => 0,
            'mode' => 0100644,
            'nlink' => 0,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => strlen($this->data ?? ''),
            'atime' => 0,
            'mtime' => 0,
            'ctime' => 0,
            'blksize' => -1,
            'blocks' => -1
        ];
    }

    public function stream_set_option($option, $arg1, $arg2)
    {
        return false;
    }
}
