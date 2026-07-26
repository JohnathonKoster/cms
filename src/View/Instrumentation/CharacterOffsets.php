<?php

namespace Statamic\View\Instrumentation;

/**
 * Converts character offsets to byte offsets in a single forward pass.
 * Shared by every consumer that bridges the parser's character-oriented
 * positions and byte-oriented string operations, so the (subtle) UTF-8
 * accounting lives in exactly one place.
 */
class CharacterOffsets
{
    /**
     * Maps each offset onto itself, clamping past the end of the source.
     * Used when byte and character space are known to coincide.
     *
     * @param  int[]  $offsets
     * @param  int  $length
     * @return array<int, int>
     */
    protected static function identity(array $offsets, $length)
    {
        $mapped = [];

        foreach ($offsets as $offset) {
            $mapped[$offset] = $offset > $length ? $length : $offset;
        }

        return $mapped;
    }

    /**
     * Maps each requested character offset to the byte offset of that
     * character's first byte. Offsets at or past the final character resolve
     * to the byte length of the source.
     *
     * @param  string  $source
     * @param  int[]  $characterOffsets
     * @return array<int, int>
     */
    public static function toBytes($source, array $characterOffsets)
    {
        if ($characterOffsets === []) {
            return [];
        }

        $byteLength = strlen($source);

        if ($byteLength === mb_strlen($source)) {
            return self::identity($characterOffsets, $byteLength);
        }

        $needed = array_fill_keys($characterOffsets, true);
        $offsets = [];
        $character = 0;

        for ($byte = 0; $byte < $byteLength; $byte++) {
            // Only the first byte of a character may satisfy an offset;
            // recording continuation bytes would split multibyte characters.
            if ((ord($source[$byte]) & 0xC0) === 0x80) {
                continue;
            }

            if (isset($needed[$character])) {
                $offsets[$character] = $byte;
            }

            $character++;
        }

        foreach ($needed as $neededCharacter => $unused) {
            if (! isset($offsets[$neededCharacter])) {
                $offsets[$neededCharacter] = $byteLength;
            }
        }

        return $offsets;
    }

    /**
     * Maps offsets produced after line-ending normalization back to the
     * original source. DocumentParser turns CRLF into one LF character before
     * assigning positions, while instrumentation edits the authored source.
     *
     * @param  string  $source
     * @param  int[]  $characterOffsets
     * @return array<int, int>
     */
    public static function normalizedToBytes($source, array $characterOffsets)
    {
        if ($characterOffsets === []) {
            return [];
        }

        $byteLength = strlen($source);

        // Only CRLF pairs and multibyte characters make the two coordinate
        // systems diverge; without either, offsets map straight through.
        if ($byteLength === mb_strlen($source) && strpos($source, "\r\n") === false) {
            return self::identity($characterOffsets, $byteLength);
        }

        $needed = array_fill_keys($characterOffsets, true);
        $offsets = [];
        $character = 0;

        for ($byte = 0; $byte < $byteLength; $byte++) {
            if ((ord($source[$byte]) & 0xC0) === 0x80) {
                continue;
            }

            if (isset($needed[$character])) {
                $offsets[$character] = $byte;
            }

            if ($source[$byte] === "\r" && $byte + 1 < $byteLength && $source[$byte + 1] === "\n") {
                $byte++;
            }

            $character++;
        }

        foreach ($needed as $neededCharacter => $unused) {
            if (! isset($offsets[$neededCharacter])) {
                $offsets[$neededCharacter] = $byteLength;
            }
        }

        return $offsets;
    }

    /**
     * The inverse: maps byte offsets onto character offsets in one pass.
     * Offsets at or past the end of the source resolve to the character
     * length.
     *
     * @param  string  $source
     * @param  int[]  $byteOffsets
     * @return array<int, int>
     */
    public static function toCharacters($source, array $byteOffsets)
    {
        if ($byteOffsets === []) {
            return [];
        }

        $byteLength = strlen($source);

        if ($byteLength === mb_strlen($source)) {
            return self::identity($byteOffsets, $byteLength);
        }

        $needed = array_fill_keys($byteOffsets, true);
        $offsets = [];
        $character = 0;

        for ($byte = 0; $byte < $byteLength; $byte++) {
            if (isset($needed[$byte])) {
                $offsets[$byte] = $character;
            }

            if ((ord($source[$byte]) & 0xC0) !== 0x80) {
                $character++;
            }
        }

        foreach ($needed as $neededByte => $unused) {
            if (! isset($offsets[$neededByte])) {
                $offsets[$neededByte] = $character;
            }
        }

        return $offsets;
    }
}
