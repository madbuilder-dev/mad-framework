<?php

namespace Mad\Component;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Mad\Database\TenantContext;

/**
 * Ordem das gravações de um mesmo campo entre abas e janelas (fw#362).
 *
 * A fila do wire é por aba: com o mesmo registro aberto em duas abas, a
 * gravação mais velha de uma (rede lenta) podia chegar ao servidor depois da
 * mais nova da outra e sobrescrevê-la. A chamada que pede ordem
 * (`Mad.call(…, { key: 'peso:' + id, seq: true })`) leva `mad_seq_key` e
 * `mad_seq` — sequência crescente carimbada na hora da edição. Aqui fica a
 * última aceita por tela + chave + empresa; a mais velha é recusada antes de a
 * ação rodar.
 *
 * A chave é de quem escreve a tela e tem de identificar o registro e o campo
 * (`peso:7`): o framework não sabe o que a ação grava. Sem chave nada muda.
 */
final class MadWriteOrder
{
    /** Quanto tempo a última sequência aceita fica guardada (segundos). */
    public const TTL = 86400;

    /**
     * Folga para o relógio de quem chama (ms). Carimbo além de agora + folga
     * não entra na ordem: guardado, travaria a chave para as outras abas.
     */
    public const MAX_AHEAD_MS = 300000;

    /**
     * true = a gravação pode seguir (é a mais nova, ou a mesma repetida);
     * false = é mais velha que a última aceita da mesma chave.
     */
    public static function admit(string $class, string $key, int $seq): bool
    {
        if ($key === '' || $seq <= 0) {
            return true;
        }
        if ($seq > (int) floor(microtime(true) * 1000) + self::MAX_AHEAD_MS) {
            return true;
        }

        $slot = 'mad:wseq:' . sha1((TenantContext::id() ?? '') . '|' . $class . '|' . $key);
        $run  = static function () use ($slot, $seq): bool {
            if ($seq < (int) Cache::get($slot, 0)) {
                return false;
            }
            Cache::put($slot, $seq, self::TTL);

            return true;
        };

        try {
            if (Cache::getStore() instanceof LockProvider) {
                return (bool) Cache::lock($slot . ':lock', 5)->block(3, $run);
            }
        } catch (\Throwable) {
            // Trava indisponível (store sem tabela de locks, tempo esgotado):
            // segue sem ela — a conferência ainda vale fora de corrida exata.
        }

        return $run();
    }
}
