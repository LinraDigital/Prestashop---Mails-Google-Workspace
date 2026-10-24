<?php
/**
 * LD - Mail Fix
 *
 * Génère, à partir du classes/Mail.php du PrestaShop installé, un override de MailCore
 * qui force le nom de domaine annoncé dans la commande SMTP EHLO.
 *
 * Seules les méthodes qui créent un transport SMTP (et les méthodes privées dont elles
 * dépendent) sont recopiées, à l'identique, avec un seul ajout : un appel à
 * self::ldMailFixApplyLocalDomain($transport) juste après la création du transport.
 *
 * Aucune dépendance à PrestaShop : la classe est testable en ligne de commande.
 *
 * @author    Linra Digital
 * @license   Propriétaire
 */
if (!defined('_PS_VERSION_') && !defined('LDMAILFIX_STANDALONE')) {
    exit;
}

class LdMailFixGenerator
{
    const HELPER_METHOD = 'ldMailFixApplyLocalDomain';
    const CONFIG_KEY = 'LDMAILFIX_DOMAIN';

    /** @var string[] Classes de transport SMTP reconnues (Symfony Mailer et SwiftMailer) */
    private static $transportClasses = ['esmtptransport', 'swift_smtptransport'];

    /** @var array */
    private $tokens = [];

    /** @var array alias en minuscules => nom complet (sans antislash initial) */
    private $imports = [];

    /** @var array nom en minuscules => ['name', 'visibility', 'start', 'end'] */
    private $methods = [];

    /** @var string[] propriétés et constantes privées de MailCore (en minuscules) */
    private $privateMembers = [];

    /**
     * @param string $source contenu de classes/Mail.php
     *
     * @return array ['code' => string, 'methods' => string[], 'injections' => int, 'hash' => string]
     *
     * @throws RuntimeException si la structure du fichier n'est pas reconnue
     */
    public function generate($source)
    {
        $this->tokens = token_get_all($source);
        $this->imports = [];
        $this->methods = [];
        $this->privateMembers = [];

        $classIndex = $this->parseHeaderAndFindClass();
        $this->parseClassBody($classIndex);

        $targets = $this->findTransportMethods();
        if (!$targets) {
            throw new RuntimeException('Aucune création de transport SMTP trouvée dans MailCore (structure non reconnue).');
        }

        $selected = $this->resolvePrivateDependencies($targets);

        // Conserver l'ordre du fichier d'origine
        uasort($this->methods, function ($a, $b) {
            return $a['start'] - $b['start'];
        });

        $chunks = [];
        $names = [];
        $injections = 0;
        $originalSource = '';
        foreach ($this->methods as $key => $method) {
            if (!isset($selected[$key])) {
                continue;
            }
            $count = 0;
            $chunks[] = '    ' . $this->renderMethod($method, $count);
            $originalSource .= $this->rawText($method['start'], $method['end']);
            if (isset($targets[$key]) && $count < 1) {
                throw new RuntimeException('Injection impossible dans ' . $method['name'] . '() : affectation du transport non reconnue.');
            }
            $injections += $count;
            $names[] = $method['name'];
        }

        $chunks[] = $this->helperMethod();

        $code = "<?php\n"
            . "/**\n"
            . " * Override généré automatiquement par le module ld_mailfix — ne pas modifier à la main.\n"
            . " * Pour le régénérer (après une mise à jour de PrestaShop) : réinitialiser le module.\n"
            . " */\n"
            . "class Mail extends MailCore\n"
            . "{\n"
            . implode("\n\n", $chunks) . "\n"
            . "}\n";

        return [
            'code' => $code,
            'methods' => $names,
            'injections' => $injections,
            'hash' => md5($originalSource),
        ];
    }

    /**
     * Lit les "use" de premier niveau et renvoie l'index du token T_CLASS de MailCore.
     */
    private function parseHeaderAndFindClass()
    {
        $n = count($this->tokens);
        for ($i = 0; $i < $n; ++$i) {
            $t = $this->tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_NAMESPACE) {
                throw new RuntimeException('classes/Mail.php déclare un namespace : structure non reconnue.');
            }
            if ($t[0] === T_USE) {
                $i = $this->parseUse($i);
                continue;
            }
            if ($t[0] === T_CLASS) {
                $j = $this->nextSignificant($i);
                if ($j !== null && is_array($this->tokens[$j]) && $this->tokens[$j][0] === T_STRING
                    && strtolower($this->tokens[$j][1]) === 'mailcore') {
                    return $i;
                }
            }
        }

        throw new RuntimeException('Classe MailCore introuvable dans classes/Mail.php.');
    }

    /**
     * Analyse une instruction "use A\B\C [as D], ...;" et renvoie l'index du ";".
     */
    private function parseUse($i)
    {
        $n = count($this->tokens);
        $name = '';
        $alias = null;
        $expectAlias = false;
        for (++$i; $i < $n; ++$i) {
            $t = $this->tokens[$i];
            if ($t === '{') {
                throw new RuntimeException('Les "use" groupés ne sont pas pris en charge.');
            }
            if ($t === ',' || $t === ';') {
                $this->addImport($name, $alias);
                $name = '';
                $alias = null;
                $expectAlias = false;
                if ($t === ';') {
                    return $i;
                }
                continue;
            }
            if (!is_array($t) || $t[0] === T_WHITESPACE) {
                continue;
            }
            if ($t[0] === T_AS) {
                $expectAlias = true;
                continue;
            }
            if ($t[0] === T_FUNCTION || $t[0] === T_CONST) {
                // use function / use const : sans incidence ici, on ignore l'instruction
                for (; $i < $n && $this->tokens[$i] !== ';'; ++$i) {
                }

                return $i;
            }
            if ($expectAlias) {
                $alias = $t[1];
            } else {
                $name .= $t[1];
            }
        }

        return $i;
    }

    private function addImport($name, $alias)
    {
        $name = ltrim(trim($name), '\\');
        if ($name === '') {
            return;
        }
        if ($alias === null) {
            $parts = explode('\\', $name);
            $alias = end($parts);
        }
        $this->imports[strtolower($alias)] = $name;
    }

    /**
     * Repère les méthodes et les membres privés du corps de MailCore.
     */
    private function parseClassBody($classIndex)
    {
        $n = count($this->tokens);
        $i = $classIndex;
        while ($i < $n && $this->tokens[$i] !== '{') {
            ++$i;
        }
        $depth = 0;
        for (; $i < $n; ++$i) {
            $t = $this->tokens[$i];
            if ($this->opensBrace($t)) {
                ++$depth;
                continue;
            }
            if ($t === '}') {
                --$depth;
                if ($depth === 0) {
                    return;
                }
                continue;
            }
            if ($depth !== 1 || !is_array($t)) {
                continue;
            }

            if ($t[0] === T_FUNCTION) {
                $i = $this->registerMethod($i);
                continue;
            }

            if ($t[0] === T_VARIABLE || $t[0] === T_CONST) {
                if (in_array(T_PRIVATE, $this->modifiersBefore($i), true)) {
                    if ($t[0] === T_VARIABLE) {
                        $this->privateMembers[] = strtolower($t[1]);
                    } else {
                        $j = $this->nextSignificant($i);
                        if ($j !== null && is_array($this->tokens[$j])) {
                            $this->privateMembers[] = strtolower($this->tokens[$j][1]);
                        }
                    }
                }
            }
        }

        throw new RuntimeException('Fin de la classe MailCore introuvable.');
    }

    /**
     * Enregistre la méthode dont le mot-clé "function" est à l'index $i, renvoie l'index de fin.
     */
    private function registerMethod($i)
    {
        $j = $this->nextSignificant($i);
        if ($j !== null && $this->tokens[$j] === '&') {
            $j = $this->nextSignificant($j);
        }
        if ($j === null || !is_array($this->tokens[$j])) {
            return $i;
        }
        $name = $this->tokens[$j][1];

        // Début : premier modificateur sur la même déclaration
        $start = $i;
        $modifiers = [];
        for ($k = $i - 1; $k >= 0; --$k) {
            $t = $this->tokens[$k];
            if (is_array($t) && $t[0] === T_WHITESPACE) {
                continue;
            }
            if (is_array($t) && in_array($t[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT], true)) {
                $modifiers[] = $t[0];
                $start = $k;
                continue;
            }
            break;
        }

        // Fin : accolade fermante du corps
        $n = count($this->tokens);
        $k = $j;
        while ($k < $n && $this->tokens[$k] !== '{' && $this->tokens[$k] !== ';') {
            ++$k;
        }
        if ($k >= $n || $this->tokens[$k] === ';') {
            return $k; // méthode abstraite
        }
        $depth = 0;
        for (; $k < $n; ++$k) {
            if ($this->opensBrace($this->tokens[$k])) {
                ++$depth;
            } elseif ($this->tokens[$k] === '}') {
                --$depth;
                if ($depth === 0) {
                    break;
                }
            }
        }

        $visibility = 'public';
        if (in_array(T_PRIVATE, $modifiers, true)) {
            $visibility = 'private';
        } elseif (in_array(T_PROTECTED, $modifiers, true)) {
            $visibility = 'protected';
        }

        $this->methods[strtolower($name)] = [
            'name' => $name,
            'visibility' => $visibility,
            'start' => $start,
            'end' => $k,
        ];

        return $k;
    }

    /**
     * Méthodes qui instancient un transport SMTP.
     */
    private function findTransportMethods()
    {
        $targets = [];
        foreach ($this->methods as $key => $method) {
            for ($i = $method['start']; $i <= $method['end']; ++$i) {
                if ($this->isTransportNew($i)) {
                    $targets[$key] = true;
                    break;
                }
            }
        }

        return $targets;
    }

    private function isTransportNew($i)
    {
        $t = $this->tokens[$i];
        if (!is_array($t) || $t[0] !== T_NEW) {
            return false;
        }
        $j = $this->nextSignificant($i);
        if ($j === null || !is_array($this->tokens[$j])) {
            return false;
        }
        $parts = explode('\\', $this->resolveName($this->tokens[$j][1]));

        return in_array(strtolower(end($parts)), self::$transportClasses, true);
    }

    /**
     * Ajoute les méthodes privées appelées (récursivement) et refuse les membres privés.
     */
    private function resolvePrivateDependencies(array $targets)
    {
        $selected = $targets;
        $queue = array_keys($targets);
        while ($queue) {
            $key = array_shift($queue);
            $method = $this->methods[$key];
            for ($i = $method['start']; $i <= $method['end']; ++$i) {
                $t = $this->tokens[$i];
                if (!is_array($t) || !in_array(strtolower($t[1]), ['self', 'static'], true)) {
                    continue;
                }
                $j = $this->nextSignificant($i);
                if ($j === null || !is_array($this->tokens[$j]) || $this->tokens[$j][0] !== T_DOUBLE_COLON) {
                    continue;
                }
                $m = $this->nextSignificant($j);
                if ($m === null || !is_array($this->tokens[$m])) {
                    continue;
                }
                $member = strtolower($this->tokens[$m][1]);
                if (in_array($member, $this->privateMembers, true)) {
                    throw new RuntimeException('La méthode ' . $method['name'] . '() utilise un membre privé (' . $this->tokens[$m][1] . ') : override impossible.');
                }
                if ($this->tokens[$m][0] === T_STRING && isset($this->methods[$member])
                    && $this->methods[$member]['visibility'] === 'private' && !isset($selected[$member])) {
                    $selected[$member] = true;
                    $queue[] = $member;
                }
            }
        }

        return $selected;
    }

    /**
     * Restitue le code d'une méthode : commentaires retirés, noms de classes importées
     * pleinement qualifiés, appel au helper injecté après chaque création de transport.
     */
    private function renderMethod(array $method, &$injections)
    {
        $out = '';
        $parenDepth = 0;
        $assignVar = null;
        $assignDepth = 0;
        $assignIndent = '';
        $pending = null;

        for ($i = $method['start']; $i <= $method['end']; ++$i) {
            $t = $this->tokens[$i];

            if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
                if (substr($t[1], -1) === "\n") {
                    $out .= "\n";
                }
                continue;
            }

            if ($t === '(' || $t === '[') {
                ++$parenDepth;
            } elseif ($t === ')' || $t === ']') {
                --$parenDepth;
            }

            // Affectation candidate : $var = ...
            if (is_array($t) && $t[0] === T_VARIABLE) {
                $j = $this->nextSignificant($i);
                if ($j !== null && $this->tokens[$j] === '=') {
                    $assignVar = $t[1];
                    $assignDepth = $parenDepth;
                    $assignIndent = $this->indentBefore($i);
                }
            }

            if ($this->isTransportNew($i)) {
                if ($assignVar === null || !$this->directlyAssigned($i)) {
                    throw new RuntimeException('Création de transport non affectée à une variable dans ' . $method['name'] . '().');
                }
                $pending = [$assignVar, $assignDepth, $assignIndent];
            }

            $out .= $this->renderToken($i);

            if ($t === ';' || $t === '{' || $t === '}') {
                if ($t === ';' && $pending !== null && $parenDepth === $pending[1]) {
                    $out .= "\n" . $pending[2] . 'self::' . self::HELPER_METHOD . '(' . $pending[0] . ');';
                    ++$injections;
                    $pending = null;
                }
                if ($pending === null) {
                    $assignVar = null;
                }
            }
        }

        // Lignes vides superflues laissées par les commentaires retirés
        $out = preg_replace("/\n[ \t]*\n([ \t]*\n)+/", "\n\n", $out);

        return rtrim($out);
    }

    /**
     * Vérifie que "new Transport" suit directement "$var =" (éventuellement entre parenthèses).
     */
    private function directlyAssigned($newIndex)
    {
        $k = $this->prevSignificant($newIndex);
        while ($k !== null && $this->tokens[$k] === '(') {
            $k = $this->prevSignificant($k);
        }

        return $k !== null && $this->tokens[$k] === '=';
    }

    private function renderToken($i)
    {
        $t = $this->tokens[$i];
        if (!is_array($t)) {
            return $t;
        }

        if ($t[0] === T_STRING && isset($this->imports[strtolower($t[1])]) && $this->isClassReference($i)) {
            return '\\' . $this->imports[strtolower($t[1])];
        }

        if (defined('T_NAME_QUALIFIED') && $t[0] === T_NAME_QUALIFIED) {
            $parts = explode('\\', $t[1], 2);
            if (isset($this->imports[strtolower($parts[0])])) {
                return '\\' . $this->imports[strtolower($parts[0])] . '\\' . $parts[1];
            }
        }

        return $t[1];
    }

    /**
     * Un T_STRING désigne-t-il une classe (et non une méthode, une propriété, une constante…) ?
     */
    private function isClassReference($i)
    {
        $prev = $this->prevSignificant($i);
        if ($prev !== null && is_array($this->tokens[$prev])) {
            $excluded = [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_NS_SEPARATOR];
            if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
                $excluded[] = T_NULLSAFE_OBJECT_OPERATOR;
            }
            if (in_array($this->tokens[$prev][0], $excluded, true)) {
                return false;
            }
        }
        $next = $this->nextSignificant($i);
        if ($next !== null && is_array($this->tokens[$next]) && $this->tokens[$next][0] === T_NS_SEPARATOR) {
            return false;
        }
        // Argument nommé "Nom: valeur"
        if ($next !== null && $this->tokens[$next] === ':' && $prev !== null
            && ($this->tokens[$prev] === '(' || $this->tokens[$prev] === ',')) {
            return false;
        }

        return true;
    }

    private function resolveName($name)
    {
        $name = ltrim($name, '\\');
        $parts = explode('\\', $name, 2);
        if (isset($this->imports[strtolower($parts[0])])) {
            return $this->imports[strtolower($parts[0])] . (isset($parts[1]) ? '\\' . $parts[1] : '');
        }

        return $name;
    }

    private function helperMethod()
    {
        return <<<'PHP'
    private static function ldMailFixApplyLocalDomain($transport)
    {
        $domain = trim((string) Configuration::get('LDMAILFIX_DOMAIN'));
        if ($domain !== '' && is_object($transport) && method_exists($transport, 'setLocalDomain')) {
            $transport->setLocalDomain($domain);
        }
    }
PHP;
    }

    private function rawText($from, $to)
    {
        $s = '';
        for ($i = $from; $i <= $to; ++$i) {
            $s .= is_array($this->tokens[$i]) ? $this->tokens[$i][1] : $this->tokens[$i];
        }

        return $s;
    }

    /**
     * Indentation de la ligne sur laquelle se trouve le token $i.
     */
    private function indentBefore($i)
    {
        $text = '';
        for ($k = $i - 1; $k >= 0; --$k) {
            $t = $this->tokens[$k];
            $s = is_array($t) ? $t[1] : $t;
            $pos = strrpos($s, "\n");
            if ($pos !== false) {
                $text = substr($s, $pos + 1) . $text;
                break;
            }
            $text = $s . $text;
        }
        preg_match('/^[ \t]*/', $text, $m);

        return $m[0];
    }

    private function modifiersBefore($i)
    {
        $mods = [];
        for ($k = $i - 1; $k >= 0; --$k) {
            $t = $this->tokens[$k];
            if (!is_array($t)) {
                break;
            }
            if (in_array($t[0], [T_WHITESPACE, T_STRING, T_ARRAY, T_CALLABLE], true) || (defined('T_NAME_QUALIFIED') && $t[0] === T_NAME_QUALIFIED)) {
                continue; // espaces et types déclarés
            }
            if (in_array($t[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_VAR], true)
                || (defined('T_READONLY') && $t[0] === T_READONLY)) {
                $mods[] = $t[0];
                continue;
            }
            break;
        }

        return $mods;
    }

    private function opensBrace($t)
    {
        return $t === '{' || (is_array($t) && ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES));
    }

    private function isInsignificant($t)
    {
        return is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    private function nextSignificant($i)
    {
        $n = count($this->tokens);
        for (++$i; $i < $n; ++$i) {
            if (!$this->isInsignificant($this->tokens[$i])) {
                return $i;
            }
        }

        return null;
    }

    private function prevSignificant($i)
    {
        for (--$i; $i >= 0; --$i) {
            if (!$this->isInsignificant($this->tokens[$i])) {
                return $i;
            }
        }

        return null;
    }
}
