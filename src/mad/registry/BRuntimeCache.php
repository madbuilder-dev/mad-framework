<?php


class BRuntimeCache implements RegistryInterface
{
    /**
     * Static variable to store cache data
     * @var array
     */
    private static $cache = [];
    
    /**
     * Returns if the service is active
     */
    public static function enabled()
    {
        return true; // Sempre habilitado, pois não depende de extensões externas
    }
    
    /**
     * Store a variable in cache
     * @param $key    Key
     * @param $value  Value
     */
    public static function setValue($key, $value)
    {
        $prefixedKey = APPLICATION_NAME . '_' . $key;
        self::$cache[$prefixedKey] = serialize($value);
        return true;
    }
    
    /**
     * Get a variable from cache
     * @param $key    Key
     */
    public static function getValue($key)
    {
        $prefixedKey = APPLICATION_NAME . '_' . $key;
        return isset(self::$cache[$prefixedKey]) ? unserialize(self::$cache[$prefixedKey]) : false;
    }
    
    /**
     * Delete a variable from cache
     * @param $key    Key
     */
    public static function delValue($key)
    {
        $prefixedKey = APPLICATION_NAME . '_' . $key;
        
        if (isset(self::$cache[$prefixedKey])) {
            unset(self::$cache[$prefixedKey]);
            return true;
        }
        
        return false;
    }
    
    /**
     * Clear cache
     */
    public static function clear()
    {
        self::$cache = [];
        return true;
    }
}