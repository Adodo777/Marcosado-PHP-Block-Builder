<?php
namespace Marcosado\BlockBuilder;

if (!defined('ABSPATH')) exit;

class Marcosado_Security
{
    /**
     * Analyse statique du code PHP pour détecter les fonctions interdites et erreurs de syntaxe.
     * Ignore les chaînes de caractères et le HTML.
     */
    public static function analyze_code(string $code): array
    {
        $forbidden_functions = [
            'eval', 'exec', 'system', 'passthru', 'shell_exec', 
            'popen', 'proc_open', 'create_function', 'call_user_func'
        ];

        // S'assurer que le code est perçu comme du PHP par le tokenizer
        $analyze_code = trim($code);
        if (!str_starts_with($analyze_code, '<?php')) {
            $analyze_code = "<?php\n" . $analyze_code;
        }

        // On vérifie la syntaxe brute si possible (fail-safe simple)
        if (function_exists('token_get_all')) {
            try {
                $tokens = token_get_all($analyze_code, TOKEN_PARSE);
            } catch (\ParseError $e) {
                return [
                    'valid' => false,
                    'error_type' => 'Erreur de syntaxe (Parse Error)',
                    'line' => $e->getLine()
                ];
            }

            foreach ($tokens as $token) {
                if (is_array($token)) {
                    $token_id = $token[0];
                    $token_text = strtolower(trim($token[1]));
                    $token_line = $token[2];

                    if ($token_id === T_EVAL) {
                        return [
                            'valid' => false,
                            'error_type' => "Fonction interdite détectée (eval)",
                            'line' => $token_line
                        ];
                    }

                    if ($token_id === T_STRING) {
                        if (in_array($token_text, $forbidden_functions, true)) {
                            return [
                                'valid' => false,
                                'error_type' => "Fonction interdite détectée ({$token_text})",
                                'line' => $token_line
                            ];
                        }
                    }
                }
            }
        }

        return ['valid' => true];
    }
}
