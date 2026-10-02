<?php

namespace phasync\Util;

/**
 * Terminal output from markup: styled on a terminal, plain text when piped to a file, and laid
 * out the same way in both.
 *
 * `<!…>` opens a span, `<!>` closes the last one opened; spans nest. A span takes styles and
 * layout, in any order:
 *
 * - colors: black, red, green, yellow, blue, magenta, cyan, white, gray, their bright- variants
 *   (bright-red...), and backgrounds bg-<color>; attributes: bold, dim, italic, underline,
 *   blink, inverse, strike
 * - padding: `pad 20` (or `rpad 20`) makes the span exactly 20 columns, padded on the right;
 *   `lpad 20` pads on the left; `center 20` centers. Longer content is cut with "…", or
 *   without it given clip. A width may be a percentage of the terminal's: `pad 50%`.
 *
 * ```php
 * $out = new Console();
 * $out->write("<!bold pad 12>--http<!><!dim>Serve HTTP here<!>\n");
 * $out->write("<!lpad 8>1.2 MB<!> <!pad 40>$name<!>\n");
 * ```
 *
 * Text from elsewhere goes through escape() first. Widths count terminal columns: East Asian
 * wide characters and emoji take 2, combining marks and zero-width characters 0.
 */
final class Console
{
    private const STYLES = [
        'bold' => 1, 'dim' => 2, 'italic' => 3, 'underline' => 4, 'blink' => 5, 'inverse' => 7, 'strike' => 9,
    ];
    private const COLORS = ['black' => 0, 'red' => 1, 'green' => 2, 'yellow' => 3, 'blue' => 4, 'magenta' => 5, 'cyan' => 6, 'white' => 7];
    private const MARKUP = '/<!([-a-z0-9% ]*)>/';

    /** The level labels log() writes (from warning up), styled and plain. */
    private const LABELS = [
        'warning'   => ["\e[33mwarning  \e[0m ", 'warning   '],
        'error'     => ["\e[31merror    \e[0m ", 'error     '],
        'critical'  => ["\e[41;37mcritical \e[0m ", 'critical  '],
        'alert'     => ["\e[41;37malert    \e[0m ", 'alert     '],
        'emergency' => ["\e[41;37memergency\e[0m ", 'emergency '],
    ];
    private const LEVELS = ['debug' => 1, 'info' => 1, 'notice' => 1, 'warning' => 1, 'error' => 1, 'critical' => 1, 'alert' => 1, 'emergency' => 1];

    private readonly bool $color;

    /** log()'s timestamp, redone once a second. */
    private int $second  = 0;
    private string $time = '';

    /**
     * Creates a console writing to `$stream`.
     *
     * @param resource  $stream where `write()` and `log()` write
     * @param bool|null $color  styled output; null: when `$stream` is a terminal, unless the NO_COLOR environment variable is set (or FORCE_COLOR is)
     */
    public function __construct(private $stream = \STDOUT, ?bool $color = null)
    {
        $this->color = $color ?? self::detectColor($stream);
    }

    /**
     * Writes `$markup` to the stream, styled or plain as the console is.
     *
     * ```php
     * $out = new Console();
     * $out->write("<!bold pad 12>--http<!><!dim>Serve HTTP here<!>\n");
     * ```
     *
     * @param string $markup text with `<!...>` spans, see the class description
     *
     * @throws \InvalidArgumentException for a `pad`, `lpad`, `rpad` or `center` without a width
     *
     * @see Console::log
     */
    public function write(string $markup): void
    {
        \fwrite($this->stream, $this->render($markup));
    }

    /**
     * Writes a log line, in one format everywhere.
     *
     * The line has the local time to hundredths of a second, `$source` when given, the level from
     * warning up, and the message. `{key}` placeholders take the values in `$context` (underlined
     * when styled). Nothing is parsed as markup, and control characters are escaped, so the line
     * shows what was logged and can't reach a terminal as escape sequences. A line that can't be
     * written (a full disk, a reader gone) is lost silently: logging never throws.
     *
     * ```
     * 2026-09-30 08:02:12.43 3 warning   disk /var is full
     * ```
     *
     * @param string              $level   a PSR-3 level: debug, info, notice, warning, error,
     *                                     critical, alert or emergency
     * @param array<string,mixed> $context
     */
    public function log(string $level, string|\Stringable $message, array $context = [], string $source = ''): void
    {
        if (!isset(self::LEVELS[$level])) {
            throw new \InvalidArgumentException("Unknown log level '$level'");
        }
        $now = \microtime(true);
        if ((int) $now !== $this->second) {
            $this->second = (int) $now;
            $this->time   = \date('Y-m-d H:i:s', $this->second);
        }
        $cs   = (int) (($now - $this->second) * 100);
        $time = $this->time . ($cs < 10 ? ".0$cs" : ".$cs");
        $line = ($this->color ? "\e[37m$time\e[0m " : "$time ") . ('' === $source ? '' : self::text($source) . ' ');
        if (isset(self::LABELS[$level])) {
            $line .= self::LABELS[$level][$this->color ? 0 : 1];
        }
        $message = \rtrim((string) $message);
        if ([] !== $context && \str_contains($message, '{')) {
            $values = [];
            foreach ($context as $key => $value) {
                if (null === $value || \is_scalar($value) || $value instanceof \Stringable) {
                    $values['{' . $key . '}'] = $this->color ? "\e[4m" . self::text((string) $value) . "\e[24m" : (string) $value;
                }
            }
            // Plain: escaped once, values included; styled: around the underline codes
            $message = $this->color ? \strtr(self::text($message), $values) : self::text(\strtr($message, $values));
        } else {
            $message = self::text($message);
        }
        $line .= $message;
        @\fwrite($this->stream, $line . "\n");
    }

    /** Control characters other than newline and tab, escaped as in C: "\033[2J". */
    private static function text(string $text): string
    {
        return \preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $text) ? \addcslashes($text, "\0..\x08\x0B..\x1F\x7F") : $text;
    }

    /** $markup as this console writes it: styled, or plain. */
    public function render(string $markup): string
    {
        $markup = self::layout($markup);

        return $this->color ? self::ansi($markup) : self::plain($markup);
    }

    /**
     * Returns whether this console writes styled output.
     */
    public function hasColor(): bool
    {
        return $this->color;
    }

    /** The terminal's width in columns: $COLUMNS, else 80. */
    public static function columns(): int
    {
        $columns = (int) \getenv('COLUMNS');

        return $columns > 0 ? $columns : 80;
    }

    /** $markup as plain text, laid out. */
    public static function strip(string $markup): string
    {
        return self::plain(self::layout($markup));
    }

    private static function plain(string $markup): string
    {
        return \str_replace('<!!', '<!', \preg_replace(self::MARKUP, '', $markup));
    }

    /**
     * $markup with its widths and alignments resolved: spans with a width padded or cut, their
     * layout words dropped, styles left as they are.
     */
    private static function layout(string $markup): string
    {
        if (!\preg_match('/<![^>]*(?:pad|center|clip)/', $markup)) {
            return $markup; // no layout: nothing to resolve
        }
        // Parse into spans: [styles, width, align, clip, children]
        $root  = ['', null, \STR_PAD_RIGHT, false, []];
        $stack = [&$root];
        foreach (\preg_split('/(<!!|<![-a-z0-9% ]*>)/', $markup, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY) as $part) {
            if ('<!!' === $part || !\str_starts_with($part, '<!')) {
                $stack[\count($stack) - 1][4][] = $part;
            } elseif ('' === \trim(\substr($part, 2, -1))) {
                if (\count($stack) > 1) {
                    \array_pop($stack);
                }
            } else {
                $span        = self::span(\substr($part, 2, -1));
                $parent      = &$stack[\count($stack) - 1];
                $parent[4][] = $span;
                $stack[]     = &$parent[4][\count($parent[4]) - 1];
                unset($parent);
            }
        }

        return self::flatten($root[4]);
    }

    /** @return array{string, ?int, int, bool, list<mixed>} a span's styles, width, alignment, clip, children */
    private static function span(string $words): array
    {
        $styles = [];
        $width  = null;
        $align  = \STR_PAD_RIGHT;
        $clip   = false;
        $words  = \preg_split('/\s+/', \trim($words));
        for ($i = 0, $n = \count($words); $i < $n; ++$i) {
            $word = $words[$i];
            if ('clip' === $word) {
                $clip = true;
            } elseif (null !== ($pad = match ($word) {
                'pad', 'rpad' => \STR_PAD_RIGHT, 'lpad' => \STR_PAD_LEFT, 'center' => \STR_PAD_BOTH, default => null,
            })) {
                $size  = $words[++$i] ?? '';
                $width = \str_ends_with($size, '%') ? \intdiv(self::columns() * (int) $size, 100) : (int) $size;
                if (!\preg_match('/^\d+%?$/', $size)) {
                    throw new \InvalidArgumentException("'$word' needs a width, such as '$word 20' or '$word 50%'");
                }
                $align = $pad;
            } else {
                $styles[] = $word;
            }
        }

        return [\implode(' ', $styles), $width, $align, $clip, []];
    }

    /** @param list<mixed> $children */
    private static function flatten(array $children): string
    {
        $out = '';
        foreach ($children as $child) {
            if (\is_string($child)) {
                $out .= $child;
                continue;
            }
            [$styles, $width, $align, $clip, $inner] = $child;
            $inner                                   = self::flatten($inner);
            if (null !== $width) {
                $inner = self::pad(self::cut($inner, $width, $clip ? '' : '…'), $width, $align);
            }
            $out .= '' === $styles ? $inner : "<!$styles>$inner<!>";
        }

        return $out;
    }

    /** $text made safe to embed in markup: shown as it is. */
    public static function escape(string $text): string
    {
        return \str_replace('<!', '<!!', $text);
    }

    /** The columns $markup takes on a terminal. */
    public static function width(string $markup): int
    {
        $text = self::strip($markup);
        if (!\preg_match('//u', $text)) {
            return \strlen($text); // not UTF-8: a column per byte
        }
        $width = 0;
        foreach (\preg_split('//u', $text, -1, \PREG_SPLIT_NO_EMPTY) as $char) {
            $width += self::charWidth($char);
        }

        return $width;
    }

    /**
     * Returns `$markup` padded with `$pad` to `$width` columns.
     *
     * `$align` is `STR_PAD_RIGHT` (the default, left-aligned), `STR_PAD_LEFT` (right-aligned) or
     * `STR_PAD_BOTH` (centered). Wider markup is returned as it is.
     */
    public static function pad(string $markup, int $width, int $align = \STR_PAD_RIGHT, string $pad = ' '): string
    {
        $missing = $width - self::width($markup);
        if ($missing <= 0) {
            return $markup;
        }

        return match ($align) {
            \STR_PAD_LEFT => \str_repeat($pad, $missing) . $markup,
            \STR_PAD_BOTH => \str_repeat($pad, \intdiv($missing, 2)) . $markup . \str_repeat($pad, $missing - \intdiv($missing, 2)),
            default       => $markup . \str_repeat($pad, $missing),
        };
    }

    /**
     * $markup cut to at most $width columns, ending with $ellipsis when anything was cut; the
     * styles still open are closed.
     */
    public static function cut(string $markup, int $width, string $ellipsis = ''): string
    {
        if (self::width($markup) <= $width) {
            return $markup;
        }
        $width -= self::width($ellipsis);
        $out   = '';
        $used  = 0;
        $open  = 0;
        foreach (\preg_split('/(<!!|<![-a-z ]*>)/', $markup, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY) as $part) {
            if ('<!!' !== $part && \str_starts_with($part, '<!')) {
                $out .= $part;
                $open += '<!>' === $part ? -1 : 1;
                continue;
            }
            $chars = \preg_match('//u', $part) ? \preg_split('//u', $part, -1, \PREG_SPLIT_NO_EMPTY) : \str_split($part);
            foreach ('<!!' === $part ? ['<!!'] : $chars as $char) {
                $w = '<!!' === $char ? 2 : self::charWidth($char);
                if ($used + $w > $width) {
                    break 2;
                }
                $out .= $char;
                $used += $w;
            }
        }

        return $out . $ellipsis . \str_repeat('<!>', \max(0, $open));
    }

    /** $markup with its styles as ANSI escape sequences. */
    private static function ansi(string $markup): string
    {
        $stack = [];
        $out   = \preg_replace_callback(self::MARKUP, static function (array $m) use (&$stack) {
            if ('' === \trim($m[1])) {
                \array_pop($stack);
            } else {
                $stack[] = self::codes($m[1]);
            }

            // Reset, then everything still open: a closed style can't linger
            return "\e[0" . ([] === $stack ? '' : ';' . \implode(';', \array_merge(...$stack))) . 'm';
        }, $markup);

        return \str_replace('<!!', '<!', $out) . ([] === $stack ? '' : "\e[0m");
    }

    /** @return list<int> the SGR codes of a space-separated style list */
    private static function codes(string $styles): array
    {
        $codes = [];
        foreach (\preg_split('/\s+/', \trim($styles)) as $style) {
            $background = \str_starts_with($style, 'bg-');
            $name       = $background ? \substr($style, 3) : $style;
            $bright     = \str_starts_with($name, 'bright-');
            $name       = $bright ? \substr($name, 7) : $name;
            if ('gray' === $name || 'grey' === $name) {
                [$name, $bright] = ['black', true];
            }
            if (isset(self::COLORS[$name])) {
                $codes[] = ($background ? 40 : 30) + ($bright ? 60 : 0) + self::COLORS[$name];
            } elseif (isset(self::STYLES[$style])) {
                $codes[] = self::STYLES[$style];
            } else {
                throw new \InvalidArgumentException("Unknown console style '$style'");
            }
        }

        return $codes;
    }

    /** The columns a UTF-8 character takes: 0, 1 or 2. */
    private static function charWidth(string $char): int
    {
        $cp = self::codePoint($char);
        if ($cp < 0x20 || (0x7F <= $cp && $cp < 0xA0)
            || (0x0300 <= $cp && $cp <= 0x036F) || (0x1AB0 <= $cp && $cp <= 0x1AFF) || (0x1DC0 <= $cp && $cp <= 0x1DFF)
            || (0x200B <= $cp && $cp <= 0x200F) || (0x20D0 <= $cp && $cp <= 0x20FF) || (0x2060 <= $cp && $cp <= 0x2064)
            || (0xFE00 <= $cp && $cp <= 0xFE0F) || (0xFE20 <= $cp && $cp <= 0xFE2F) || (0xE0100 <= $cp && $cp <= 0xE01EF)) {
            return 0; // control characters, combining marks, zero-width characters, variation selectors
        }
        if ((0x1100 <= $cp && $cp <= 0x115F) || (0x2E80 <= $cp && $cp <= 0x303E) || (0x3041 <= $cp && $cp <= 0x33FF)
            || (0x3400 <= $cp && $cp <= 0x4DBF) || (0x4E00 <= $cp && $cp <= 0x9FFF) || (0xA000 <= $cp && $cp <= 0xA4CF)
            || (0xAC00 <= $cp && $cp <= 0xD7A3) || (0xF900 <= $cp && $cp <= 0xFAFF) || (0xFE30 <= $cp && $cp <= 0xFE4F)
            || (0xFF00 <= $cp && $cp <= 0xFF60) || (0xFFE0 <= $cp && $cp <= 0xFFE6) || (0x1F300 <= $cp && $cp <= 0x1F64F)
            || (0x1F680 <= $cp && $cp <= 0x1F6FF) || (0x1F900 <= $cp && $cp <= 0x1F9FF) || (0x1FA70 <= $cp && $cp <= 0x1FAFF)
            || (0x20000 <= $cp && $cp <= 0x3FFFD)) {
            return 2; // East Asian wide and fullwidth characters, emoji
        }

        return 1;
    }

    private static function codePoint(string $char): int
    {
        $b = \unpack('C*', $char);

        return match (\count($b)) {
            1       => $b[1],
            2       => (($b[1] & 0x1F) << 6) | ($b[2] & 0x3F),
            3       => (($b[1] & 0x0F) << 12) | (($b[2] & 0x3F) << 6) | ($b[3] & 0x3F),
            default => (($b[1] & 0x07) << 18) | (($b[2] & 0x3F) << 12) | (($b[3] & 0x3F) << 6) | ($b[4] & 0x3F),
        };
    }

    /** @param resource $stream */
    private static function detectColor($stream): bool
    {
        if (false !== \getenv('NO_COLOR') && '' !== \getenv('NO_COLOR')) {
            return false;
        }
        if (false !== \getenv('FORCE_COLOR') && '' !== \getenv('FORCE_COLOR')) {
            return true;
        }
        if (!\stream_isatty($stream)) {
            return false;
        }

        return \PHP_OS_FAMILY !== 'Windows' || sapi_windows_vt100_support($stream, true);
    }
}
