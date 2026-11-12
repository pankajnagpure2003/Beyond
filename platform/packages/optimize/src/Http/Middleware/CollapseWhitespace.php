<?php

namespace Botble\Optimize\Http\Middleware;

class CollapseWhitespace extends PageSpeed
{
    public function apply(string $buffer): string
    {
        $replace = [
            '/\>[^\S ]+/s' => '>',
            '/[^\S ]+\</s' => '<',
            '/(\s)+/s' => '\\1',
        ];

        // Line breaks are meaningful inside these tags, so they are pulled out
        // before collapsing and put back afterwards. Collapsing a <script> onto
        // a single line makes the first "//" comment swallow the rest of the
        // block, which breaks the whole script with a syntax error.
        $preserved = [];

        $buffer = preg_replace_callback(
            '#<(pre|textarea|script)\b[^>]*>.*?</\1\s*>#is',
            function (array $matches) use (&$preserved): string {
                $placeholder = sprintf('<!--bb-preserved-block-%d-->', count($preserved));

                $preserved[$placeholder] = $matches[0];

                return $placeholder;
            },
            $buffer
        );

        $buffer = $this->replace($replace, $buffer);

        if (! $preserved) {
            return $buffer;
        }

        return str_replace(array_keys($preserved), array_values($preserved), $buffer);
    }
}
