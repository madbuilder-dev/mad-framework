<?php
namespace Mad\Core;

/**
 * Handles action permission verification and visibility control.
 *
 * This class allows setting a callback function to verify action permissions
 * and determines whether restricted actions should be hidden or not.
 *
 * @version    1.0
 * @package    core
 * @author     Matheus Agnes Dias
 * @copyright  Copyright (c) 2025-2026 Mad Solutions LTDA (https://madbuilder.dev)
 */
class BuilderApplication
{
    private static $verifyActionPermission;
    private static $hideAction = true;

    /**
     * Sets the callback function for verifying action permissions.
     *
     * The provided callback will be used to determine whether a user has permission 
     * to execute an action. Additionally, it sets whether restricted actions should 
     * be hidden or not.
     *
     * @param callable $callback The function to verify action permissions.
     * @param string   $type     Defines the behavior when permission is denied. 
     *                           Accepts 'hide' (default) to hide the action or 
     *                           any other value to keep it visible but restricted.
     *
     * @return void
     */
    public static function setVerifyActionPermission(Callable $callback, $type = 'hide')
    {
        self::$verifyActionPermission = $callback;
        self::$hideAction = $type == 'hide' ? true : false;
    }

    /**
     * Retrieves the callback function for action permission verification.
     *
     * @return callable|null The permission verification callback, or null if not set.
     */
    public static function getVerifyActionPermission()
    {
        return self::$verifyActionPermission;
    }

    /**
     * Checks whether restricted actions should be hidden.
     *
     * @return bool True if actions should be hidden when access is denied, false otherwise.
     */
    public static function isHideAction()
    {
        return self::$hideAction;
    }
}
