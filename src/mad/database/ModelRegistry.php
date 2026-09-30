<?php

namespace Mad\Database;

use Illuminate\Database\Eloquent\Model;
use Mad\Registry\AbstractClassRegistry;

/**
 * ModelRegistry — resolve um identificador de model para o FQCN real, varrendo
 * `App\Models\**` recursivamente. Fonte ÚNICA de resolução string -> classe.
 * Estende {@see AbstractClassRegistry} (esqueleto comum com Control/ServiceRegistry).
 *
 * Pós-normalização os models vivem em sub-namespaces por domínio:
 *   App\Models\Iam\User       (mad_iam_user)      -> token "IamUser", bare "User"
 *   App\Models\Comm\Message   (mad_comm_message)  -> token "CommMessage", bare "Message"
 *   App\Models\Ged\Document   (mad_ged_document)  -> token "GedDocument", bare "Document"
 *   App\Models\TesteCliente   (flat, demo)        -> "TesteCliente"
 *
 * Índices construídos:
 *  - token DomainEntity (sempre único): "IamUser", "CommMessage", "GedDocument".
 *    É o handle público canônico usado nos blades (`model="IamUser"`).
 *  - basename de entidade quando único: "Message", "Document", "Token", "User".
 *    Em colisão (>1 claimant) o model FLAT/app-space vence o bare; senão só por
 *    token DomainEntity ou FQCN — desambiguação determinística.
 *
 * Aceita os três mundos em {@see resolve()}: FQCN explícito, token DomainEntity e
 * basename de entidade. Memoizado por processo. State-agnostic: funciona tanto com
 * os models flat (pré-migração) quanto sub-namespaced (pós), resolvendo o que existir.
 *
 * Só subclasses de Model entram no índice ({@see accepts()}): ignora traits
 * (Concerns\*), interfaces, enums e não-models que vivam sob app/Models.
 *
 * Home em Mad\Database (prefixo PSR-4 comprovado, dir src/mad/database/ já existe) —
 * evita o landmine de autoload de prefixo novo no CI Linux.
 */
class ModelRegistry extends AbstractClassRegistry
{
    /** @var array<string,string>|null token|bare => FQCN (cache PRÓPRIO). */
    protected static ?array $map = null;

    protected static function baseNamespace(): string
    {
        return 'App\\Models\\';
    }

    protected static function relativeDir(): string
    {
        return 'app/Models';
    }

    /** Só subclasses de Model entram no índice (ignora Concerns\*, enums, ifaces). */
    protected static function accepts(string $fqcn): bool
    {
        return class_exists($fqcn) && is_subclass_of($fqcn, Model::class);
    }

    /** @param list<array{fqcn:string,domain:string,class:string}> $discovered */
    protected static function buildMap(array $discovered): array
    {
        $map    = [];
        $byBase = []; // basename => list<array{fqcn,domain,class}> p/ resolver colisão

        foreach ($discovered as $m) {
            // token público: DomainEntity (sub-ns) ou o próprio nome (flat)
            $token       = $m['domain'].$m['class'];
            $map[$token] = $m['fqcn'];

            $byBase[$m['class']][] = $m;
        }

        // basename de entidade: único -> registra; colisão -> flat vence; ambíguo -> ignora
        foreach ($byBase as $base => $claimants) {
            if (isset($map[$base])) {
                continue; // já é token de um model flat (ex "User" bridge, "TesteCliente")
            }
            if (count($claimants) === 1) {
                $map[$base] = $claimants[0]['fqcn'];

                continue;
            }
            $flat = array_values(array_filter($claimants, static fn ($c) => $c['domain'] === ''));
            if (count($flat) === 1) {
                $map[$base] = $flat[0]['fqcn'];
            }
            // senão: ambíguo entre domínios -> alcançável só por token DomainEntity/FQCN
        }

        return $map;
    }
}
