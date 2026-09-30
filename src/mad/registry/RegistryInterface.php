<?php
namespace Mad\Registry;

/**
 * Contract for a static key/value store used as a request- or process-wide
 * cache (see BRuntimeCache). Signatures stay untyped so existing
 * implementations keep compiling.
 *
 * @author     Matheus Agnes Dias
 * @copyright  Copyright (c) 2025-2026 Mad Solutions LTDA (https://madbuilder.dev)
 * @license    MIT
 */
interface RegistryInterface
{
    /** Whether the backing store can be used in this environment. */
    public static function enabled();

    /** Stores $value under $key, replacing any previous value. */
    public static function setValue($key, $value);

    /** Value stored under $key; false when the key is missing. */
    public static function getValue($key);

    /** Removes $key from the store. */
    public static function delValue($key);

    /** Removes every key from the store. */
    public static function clear();
}
