<?php

/**
 * MAD framework — i18n global helpers.
 *
 * Provides mad_t() as a short alias for MadLang::t().
 */

if (!function_exists('mad_t')) {
    /**
     * Translate a MAD framework key.
     *
     *   mad_t('mad.btn.clear')
     *   mad_t('mad.dashf.filter_by', ['label' => 'Status'])
     *
     * @param string $key      Dot-notated key path
     * @param array  $replace  Placeholder substitutions (:name)
     */
    function mad_t(string $key, array $replace = []): string
    {
        return \Mad\I18n\MadLang::t($key, $replace);
    }
}
