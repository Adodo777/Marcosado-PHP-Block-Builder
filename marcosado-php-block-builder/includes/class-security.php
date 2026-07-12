<?php
namespace Marcosado\BlockBuilder;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Marcosado_Security
{
    /**
     * Analyse statique du code PHP pour détecter les fonctions interdites et vulnérabilités.
     * Utilise token_get_all pour parser le code PHP brut sans l'exécuter.
     *
     * @return array{
     *   valid: bool,
     *   severity: string,
     *   score: int,
     *   findings: array<int, array{rule_id:string,severity:string,message:string,line:int|null}>,
     *   error_type: string,
     *   line: int|null
     * }
     */
    public static function analyze_code(string $code): array
    {
        $result = [
            'valid'      => true,
            'severity'   => 'ok',
            'score'      => 0,
            'findings'   => [],
            'error_type' => '',
            'line'       => null,
        ];

        $critical_functions = [
            'eval',
            'exec',
            'system',
            'passthru',
            'shell_exec',
            'popen',
            'proc_open',
            'create_function',
            'assert',
            'pcntl_exec',
        ];

        $warning_functions = [
            'call_user_func',
            'call_user_func_array',
            'base64_decode',
            'gzinflate',
            'gzdecode',
            'gzuncompress',
            'str_rot13',
            'strrev',
            'file_put_contents',
            'fopen',
            'curl_exec',
            'fsockopen',
            'pfsockopen',
            'ini_set',
            'error_reporting',
            'set_time_limit',
            'ignore_user_abort',
        ];

        $source = trim($code);
        if ( ! str_starts_with($source, '<?php') ) {
            $source = "<?php\n" . $source;
        }

        if ( ! function_exists('token_get_all') ) {
            return $result;
        }

        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $e) {
            return self::fail($result, 'critical', 'syntax.parse_error', 'Erreur de syntaxe (Parse Error)', $e->getLine());
        }

        $obfuscation_score = 0;
        $base64_hits = 0;
        $inflate_hits = 0;
        $transform_hits = 0;
        $long_string_hits = 0;

        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === '`') {
                    return self::fail($result, 'critical', 'exec.backticks', 'Utilisation des backticks (exécution système) interdite', self::get_line($tokens, $i));
                }

                if ($token === '$') {
                    $next = self::get_next_meaningful_token_struct($tokens, $i);
                    if ($next && is_array($next) && $next['id'] === T_VARIABLE) {
                        self::add_finding($result, 'vars.variable_variables', 'warning', 'Variable variable détectée ($$var)', $next['line']);
                    }
                }

                continue;
            }

            $token_id   = $token[0];
            $token_text = trim($token[1]);
            $token_line = $token[2];
            $lower      = strtolower($token_text);

            if ($token_id === T_EVAL) {
                return self::fail($result, 'critical', 'exec.eval', 'Fonction interdite détectée (eval)', $token_line);
            }

            if ($token_id === T_VARIABLE) {
                if (self::is_variable_function_call($tokens, $i)) {
                    return self::fail($result, 'critical', 'call.variable_function', 'Appel de fonction dynamique interdit ($variable())', $token_line);
                }
            }

            if (in_array($token_id, [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                if ( ! self::is_safe_literal_include($tokens, $i) ) {
                    return self::fail($result, 'critical', 'include.dynamic', 'Inclusion de fichier non littérale interdite', $token_line);
                }
                continue;
            }

            if ($token_id === T_STRING) {
                if (self::is_real_function_call($tokens, $i)) {
                    if (in_array($lower, $critical_functions, true)) {
                        return self::fail($result, 'critical', 'func.' . $lower, "Fonction interdite détectée ({$lower})", $token_line);
                    }

                    if (in_array($lower, $warning_functions, true)) {
                        self::add_finding($result, 'func.' . $lower, 'warning', "Fonction sensible détectée ({$lower})", $token_line);

                        if ($lower === 'base64_decode') {
                            $base64_hits++;
                            $obfuscation_score += 20;
                        }

                        if (in_array($lower, ['gzinflate', 'gzdecode', 'gzuncompress'], true)) {
                            $inflate_hits++;
                            $obfuscation_score += 25;
                        }

                        if (in_array($lower, ['str_rot13', 'strrev'], true)) {
                            $transform_hits++;
                            $obfuscation_score += 15;
                        }

                        if (in_array($lower, ['call_user_func', 'call_user_func_array'], true)) {
                            $obfuscation_score += 20;
                        }
                    }
                }
            }

            if ($token_id === T_CONSTANT_ENCAPSED_STRING || $token_id === T_ENCAPSED_AND_WHITESPACE) {
                if (strlen($token_text) > 400) {
                    $long_string_hits++;
                    $obfuscation_score += 10;
                }
            }
        }

        if ($base64_hits > 0 && ($inflate_hits > 0 || $transform_hits > 0)) {
            self::add_finding($result, 'obfuscation.chain', 'critical', 'Chaîne d’obfuscation suspecte détectée', null);
        } elseif ($obfuscation_score >= 40 || ($base64_hits > 0 && $long_string_hits > 0)) {
            self::add_finding($result, 'obfuscation.suspected', 'warning', 'Obfuscation potentielle détectée', null);
        }

        self::finalize($result);

        return $result;
    }

    private static function is_real_function_call(array $tokens, int $index): bool
    {
        $current = $tokens[$index];
        if ( ! is_array($current) || $current[0] !== T_STRING ) {
            return false;
        }

        $prev = self::get_prev_meaningful_token_struct($tokens, $index);
        $next = self::get_next_meaningful_token_struct($tokens, $index);

        if ( ! $next || $next['text'] !== '(' ) {
            return false;
        }

        if ($prev) {
            $nullsafe_id = defined('T_NULLSAFE_OBJECT_OPERATOR') ? T_NULLSAFE_OBJECT_OPERATOR : -1;
            if ($prev['text'] === '->' || $prev['text'] === '::' || $prev['text'] === '\\' || $prev['text'] === '?->' || (is_int($prev['id']) && $prev['id'] === $nullsafe_id)) {
                return false;
            }

            if (is_int($prev['id']) && in_array($prev['id'], [T_FUNCTION, T_NEW], true)) {
                return false;
            }
        }

        return true;
    }

    private static function is_variable_function_call(array $tokens, int $index): bool
    {
        $current = $tokens[$index];
        if ( ! is_array($current) || $current[0] !== T_VARIABLE ) {
            return false;
        }

        $prev = self::get_prev_meaningful_token_struct($tokens, $index);
        $next = self::get_next_meaningful_token_struct($tokens, $index);

        if ( ! $next || $next['text'] !== '(' ) {
            return false;
        }

        // We intentionally do NOT exclude -> or :: here. 
        // A variable function call like $obj->$method() or Class::$method() 
        // is just as dangerous as $func() and must be flagged.

        return true;
    }

    private static function is_safe_literal_include(array $tokens, int $index): bool
    {
        $expr = self::read_expression_after_keyword($tokens, $index);

        if ($expr === []) {
            return false;
        }

        if (count($expr) === 1) {
            $item = $expr[0];
            if (is_array($item) && $item[0] === T_CONSTANT_ENCAPSED_STRING) {
                return true;
            }
        }

        if (count($expr) === 3) {
            $t0 = $expr[0];
            $t1 = $expr[1];
            $t2 = $expr[2];

            $id0 = is_array($t0) ? $t0[0] : null;
            $txt1 = is_array($t1) ? $t1[1] : $t1;
            $id2 = is_array($t2) ? $t2[0] : null;

            if ($id0 === T_DIR && $txt1 === '.' && $id2 === T_CONSTANT_ENCAPSED_STRING) {
                return true;
            }
        }

        return false;
    }

    private static function read_expression_after_keyword(array $tokens, int $index): array
    {
        $expr = [];
        $depth = 0;

        for ($i = $index + 1, $max = count($tokens); $i < $max; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($token === ';' && $depth === 0) {
                break;
            }

            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            }

            $expr[] = $token;
        }

        return $expr;
    }

    private static function finalize(array &$result): void
    {
        $critical = array_values(array_filter($result['findings'], static function ($finding) {
            return $finding['severity'] === 'critical';
        }));

        $warning = array_values(array_filter($result['findings'], static function ($finding) {
            return $finding['severity'] === 'warning';
        }));

        if ( ! empty($critical) ) {
            $result['valid'] = false;
            $result['severity'] = 'critical';
            $result['score'] = max($result['score'], 100);
            $result['error_type'] = $critical[0]['message'];
            $result['line'] = $critical[0]['line'];
            return;
        }

        if ( ! empty($warning) ) {
            $result['valid'] = true;
            $result['severity'] = 'warning';
            $result['score'] = max($result['score'], 40);
            $result['error_type'] = $warning[0]['message'];
            $result['line'] = $warning[0]['line'];
            return;
        }

        $result['valid'] = true;
        $result['severity'] = 'ok';
        $result['score'] = 0;
    }

    private static function add_finding(array &$result, string $rule_id, string $severity, string $message, ?int $line): void
    {
        $result['findings'][] = [
            'rule_id'  => $rule_id,
            'severity' => $severity,
            'message'  => $message,
            'line'     => $line,
        ];

        if ($severity === 'critical') {
            $result['score'] += 100;
        } elseif ($severity === 'warning') {
            $result['score'] += 20;
        } else {
            $result['score'] += 5;
        }
    }

    private static function fail(array $result, string $severity, string $rule_id, string $message, int $line): array
    {
        $result['valid'] = false;
        $result['severity'] = $severity;
        $result['error_type'] = $message;
        $result['line'] = $line;
        $result['score'] = 100;
        $result['findings'][] = [
            'rule_id'  => $rule_id,
            'severity' => $severity,
            'message'  => $message,
            'line'     => $line,
        ];

        return $result;
    }

    private static function get_next_meaningful_token_struct(array $tokens, int $current_index): ?array
    {
        for ($i = $current_index + 1, $max = count($tokens); $i < $max; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                return [
                    'id'   => $token[0],
                    'text' => $token[1],
                    'line' => $token[2],
                ];
            }

            if (trim($token) === '') {
                continue;
            }

            return [
                'id'   => null,
                'text' => $token,
                'line' => self::get_line($tokens, $i),
            ];
        }

        return null;
    }

    private static function get_prev_meaningful_token_struct(array $tokens, int $current_index): ?array
    {
        for ($i = $current_index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                return [
                    'id'   => $token[0],
                    'text' => $token[1],
                    'line' => $token[2],
                ];
            }

            if (trim($token) === '') {
                continue;
            }

            return [
                'id'   => null,
                'text' => $token,
                'line' => self::get_line($tokens, $i),
            ];
        }

        return null;
    }

    private static function get_line(array $tokens, int $index): int
    {
        for ($i = $index; $i >= 0; $i--) {
            if (is_array($tokens[$i])) {
                return $tokens[$i][2];
            }
        }

        return 0;
    }
}
