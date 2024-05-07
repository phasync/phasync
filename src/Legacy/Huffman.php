<?php
namespace phasync\Legacy;

use LogicException;

final class Huffman {

    /**
     * If not null, this bitmask will be appended to the result.
     * 
     * @var null|int
     */
    private ?int $paddingCode = null;

    /**
     * An array of 256 integers, each representing the Huffman code
     * 
     * @var int[]
     */
    private array $table;

    /**
     * The reverse Huffman tree
     * 
     * @var object
     */
    private object $tree;

    /**
     * An array of 256 integers, each representing the number of bits in the
     * corresponding Huffman in {@see Huffman::$table}
     * 
     * @var int[]
     */
    private array $lengths;

    /**
     * Expects a Huffman table of 256 integer values. 
     * @param array $table 
     * @return void 
     */
    public function __construct(int $paddingCode = null, int ...$table) {
        $this->paddingCode = $paddingCode;
        $this->table = $table;
        $this->tree = self::node();

        // Build the lengths array
        $lengths = [];
        foreach ($this->table as $index => $code) {
            if ($code >> 32) {
                throw new LogicException("Only up to 32 bit huffman codes allowed");
            }

            $lengths[] = strlen(decbin($code));
            $this->insertCode($code, chr($index));
        }
        $this->lengths = $lengths;
    }

    private function insertCode(int $code, string $value) {
        $currentNode = $this->tree;

        foreach (\str_split(\decbin($code)) as $bit) {
            if ($bit === '0') {
                if ($currentNode->left === null) {
                    $currentNode->left = self::node();
                }
                $currentNode = $currentNode->left;
            } else {
                if ($currentNode->right === null) {
                    $currentNode->right = self::node();
                }
                $currentNode = $currentNode->right;
            }
        }
        $currentNode->value = $value;
    }

    public function encode(string $data): string {
        $result = [];
        $bitBuffer = 0;
        $bitFree = 8;

        for ($i = 0, $length = strlen($data); $i < $length; $i++) {
            $char = ord($data[$i]);
            $code = $this->table[$char];
            $codeLength = $this->lengths[$char];

            while ($codeLength > 0) {
                $bitsToTake = min($bitFree, $codeLength);
                $bits = $code >> ($codeLength - $bitsToTake);
                $codeLength -= $bitsToTake;
                $bitBuffer |= $bits << ($bitFree - $bitsToTake);
                $bitFree -= $bitsToTake;
                if ($bitFree === 0) {
                    $result[] = $bitBuffer;
                    $bitBuffer = 0;
                    $bitFree = 8;
                }
            }
        }

        // Handle padding
        if ($this->paddingCode !== null) {
            $paddingLength = $this->lengths[256]; // Using 256 index for padding code
            while ($paddingLength > 0) {
                $bitsToTake = min($bitFree, $paddingLength);
                $bits = $this->paddingCode >> ($paddingLength - $bitsToTake);
                $paddingLength -= $bitsToTake;
                $bitBuffer |= $bits << ($bitFree - $bitsToTake);
                $bitFree -= $bitsToTake;
                if ($bitFree === 0) {
                    $result[] = $bitBuffer;
                    $bitBuffer = 0;
                    $bitFree = 8;
                }
            }
        } else {
            // Simple zero-bit padding to fill the last byte
            while ($bitFree > 0) {
                $bitBuffer <<= 1;
                $bitFree--;
            }
            if ($bitFree === 0) {
                $result[] = $bitBuffer;
            }
        }

        return pack("C*", ...$result);
    }

    public function decode(string $data): string {
        $result = '';
        $currentNode = $this->tree;
    
        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $byte = ord($data[$i]);
            for ($bit = 7; $bit >= 0; --$bit) {
                if (($byte >> $bit) & 1) {
                    if ($currentNode->right !== null) {
                        $currentNode = $currentNode->right;
                    } else {
                        // We have hit padding or an incorrect code path
                        break 2; // Exit both loops
                    }
                } else {
                    if ($currentNode->left !== null) {
                        $currentNode = $currentNode->left;
                    } else {
                        // We have hit padding or an incorrect code path
                        break 2; // Exit both loops
                    }
                }
                if ($currentNode->isLeaf()) {
                    $result .= $currentNode->value;
                    $currentNode = $this->tree; // Reset to start for next character
                }
            }
        }
    
        return $result;
    }

    private static function node() {
        return new class() {
            public object $left = null;
            public object $right = null;
            public ?string $value = null;

            public function isLeaf(): bool {
                return $this->value !== null;
            }
        };
    }
}