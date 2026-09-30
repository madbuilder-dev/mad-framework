<?php

namespace Mad\Util;

/**
 * MadTempo — opcoes de periodo (meses/anos) pra filtros e combos.
 *
 * Substitui a dependencia app-level TempoService dentro do lib/mad.
 * Sem acesso a banco, sem estado (nomes de mes vem do catalogo MadLang).
 */
class MadTempo
{
    /** Mapa numero do mes (zero-padded) => nome no locale ativo (MadLang). */
    public static function getMeses(): array
    {
        $meses = [];
        foreach (['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'] as $m) {
            $meses[$m] = \Mad\I18n\MadLang::t('mad.tempo.m' . $m);
        }
        return $meses;
    }

    /**
     * Anos em torno do atual, como [ano => ano] (string).
     *
     * @param int $back  anos pra tras a partir do atual (default 5)
     * @param int $ahead anos pra frente a partir do atual (default 5)
     */
    public static function getAnos(int $back = 5, int $ahead = 5): array
    {
        $atual = (int) date('Y');
        $anos  = [];
        for ($y = $atual - $back; $y <= $atual + $ahead; $y++) {
            $anos[(string) $y] = (string) $y;
        }
        return $anos;
    }
}
