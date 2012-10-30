<?php
/**
 * A function that can highlight a string that contains Saffire syntax.
 * 
 * @param string $text
 * @author Richard van Velzen https://github.com/rvanvelzen
 */
function highlightSaffire($text) {
    $pointer = 0;
    $length = strlen($text);
    $output = array();

    $ST_REGEX = 0;
    $ST_DIV = 1;

    $state = $ST_REGEX; // 0 is st_regex, 1 is st_div

    while ($pointer < $length) {
        if (!preg_match('~(?x)
              (?P<comment> //.* | /\*([^*]|\*[^/])*\*/ )
            | (?P<keyword> \b (?:
                if | else | use | as | do | for | foreach | switch | class | extends
              | implements | abstract | final | interface | const | static | public
              | private | protected | method | readonly | property | catch | finally
              | throw | return | break | breakelse | continue | try | default | goto
              | case | self | parent | yield | in | import | alias
            ) \b )
            | (?P<operator> (?: [<>]{2} | [-+*/%&|^\~<>=!] ) = | [|&<>+-]{2} | [][+%<>(){}:;=,.?!*^|-]
                ' . ($state === $ST_DIV ? ' | / ' : '') . ')
            | (?P<regex> ' . ($state === $ST_DIV ? '(?!)' : '
                /[^/\\\\]*(?:\\\\.[^/\\\\]*)*/[a-z]*
            ' ) . ')
            | (?P<identifier> ::\+ | [a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]* | [a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]* [?!]? )
            | (?P<string> "[^"]*" | \'[^\']*\' )
            | (?P<whitespace> \s+ )
            | (?P<number> \d+ )
            | (?P<error> [\s\S] )
        ~u', $text, $match, null, $pointer)) {
            break;
        }

        $lexeme = htmlspecialchars($match[0]);

        $type = null;
        foreach (array(
            'comment' => array('comment', null),
            'keyword' => array('keyword', $ST_REGEX),
            'identifier' => array('identifier', $ST_DIV),
            'string' => array('string', $ST_DIV),
            'number' => array('number', $ST_DIV),
            'operator' => array('operator', $ST_REGEX),
            'regex' => array('regex', $ST_DIV),
            'whitespace' => array(null, null)
        ) as $index => $class) {
            if (isset($match[$index]) && strlen($match[$index])) {
                list($class, $newState) = $class;
                $type = $class;

                if ($newState !== null) {
                    $state = $newState;
                }

                break;
            }
        }

        $output[] = $type ? '<span class="' . $type . '">' . $lexeme . '</span>' : $lexeme;
        $pointer += strlen($match[0]);
    }

    return implode($output);
}