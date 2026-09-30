<?php

namespace Mad\Ai\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mad\Ai\DashboardStore;
use Mad\Mcp\McpCurrentUser;
use Mad\Mcp\McpManifestLoader;

/**
 * EmbedDashboardController — dashboards do usuário final ("Meus Dashboards").
 *
 *   GET    /embed/v1/dashboards             meus + compartilhados comigo
 *   POST   /embed/v1/dashboards             cria {title, layout?}
 *   GET    /embed/v1/dashboards/{id}        meta + layout (+ shares p/ dono)
 *   PATCH  /embed/v1/dashboards/{id}        title/layout/filters (dono)
 *   DELETE /embed/v1/dashboards/{id}        exclui (dono)
 *   POST   /embed/v1/dashboards/{id}/home   marca tela inicial (dono) + frontpage
 *   PUT    /embed/v1/dashboards/{id}/share  {users:[], groups:[]} (dono)
 *   GET    /embed/v1/share-targets          usuários+grupos p/ o modal de share
 *
 * "Tela inicial": além do is_home (qual dashboard abre primeiro), garante o
 * Program da tela MyDashboards e seta user.frontpage_id — o login passa a cair
 * direto na tela de dashboards (mecanismo frontpage existente do LoginForm).
 */
final class EmbedDashboardController
{
    public function list(Request $request): JsonResponse
    {
        $userId = McpCurrentUser::id();
        if ($userId === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        return response()->json([
            'dashboards' => self::store()->listVisible((int) $userId, McpCurrentUser::groupIds()),
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $userId = McpCurrentUser::id();
        if ($userId === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $title  = trim((string) $request->input('title', ''));
        $layout = $request->input('layout');
        $id     = self::store()->create((int) $userId, $title, is_array($layout) ? $layout : []);

        if ($id === null) {
            return response()->json(['error' => 'create_failed'], 500);
        }

        return response()->json(['id' => $id, 'title' => $title !== '' ? $title : 'Novo dashboard'], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $userId = McpCurrentUser::id();
        if ($userId === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $dash = self::store()->getVisible($id, (int) $userId, McpCurrentUser::groupIds());
        if ($dash === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        if ($dash['canEdit']) {
            $dash['shares'] = self::store()->shares($id);
        }
        unset($dash['ownerId']);

        return response()->json($dash);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $userId = McpCurrentUser::id();
        if ($userId === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $title   = $request->has('title') ? (string) $request->input('title') : null;
        $layout  = $request->has('layout') && is_array($request->input('layout')) ? $request->input('layout') : null;
        $filters = $request->has('filters') && is_array($request->input('filters')) ? $request->input('filters') : null;

        return self::store()->update($id, (int) $userId, $title, $layout, $filters)
            ? response()->json(['ok' => true])
            : response()->json(['error' => 'not_found'], 404);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $userId = McpCurrentUser::id();
        if ($userId === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        return self::store()->delete($id, (int) $userId)
            ? response()->json(['ok' => true])
            : response()->json(['error' => 'not_found'], 404);
    }

    public function home(Request $request, string $id): JsonResponse
    {
        $userId = McpCurrentUser::id();
        if ($userId === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        if (! self::store()->setHome($id, (int) $userId)) {
            return response()->json(['error' => 'not_found'], 404);
        }

        // Frontpage do login → tela MyDashboards (best-effort: o is_home já
        // valeu; se o iam estiver indisponível, só loga).
        try {
            $program = \App\Models\Iam\Program::query()->firstOrCreate(
                ['controller' => 'MyDashboards'],
                ['name' => 'Meus Dashboards'],
            );
            \App\Models\Iam\User::query()->whereKey((int) $userId)->update(['frontpage_id' => $program->id]);
        } catch (\Throwable $e) {
            error_log('[embed-dash] frontpage: ' . $e->getMessage());
        }

        return response()->json(['ok' => true]);
    }

    public function share(Request $request, string $id): JsonResponse
    {
        $userId = McpCurrentUser::id();
        if ($userId === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $users  = $request->input('users');
        $groups = $request->input('groups');

        $ok = self::store()->setShares(
            $id,
            (int) $userId,
            is_array($users) ? $users : [],
            is_array($groups) ? $groups : [],
        );

        return $ok
            ? response()->json(['ok' => true])
            : response()->json(['error' => 'not_found'], 404);
    }

    /** Usuários + grupos do sistema p/ o modal de compartilhamento (id + nome). */
    public function shareTargets(Request $request): JsonResponse
    {
        $userId = McpCurrentUser::id();
        if ($userId === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $users  = [];
        $groups = [];

        try {
            $users = \App\Models\Iam\User::query()
                ->where('active', 'Y')
                ->whereKeyNot((int) $userId)
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name'])
                ->map(static fn ($u) => ['id' => (int) $u->id, 'name' => (string) $u->name])
                ->all();
        } catch (\Throwable $e) {
            error_log('[embed-dash] share-targets users: ' . $e->getMessage());
        }

        try {
            $groups = \App\Models\Iam\Group::query()
                ->orderBy('name')
                ->limit(200)
                ->get(['id', 'name'])
                ->map(static fn ($g) => ['id' => (int) $g->id, 'name' => (string) $g->name])
                ->all();
        } catch (\Throwable $e) {
            error_log('[embed-dash] share-targets groups: ' . $e->getMessage());
        }

        return response()->json(['users' => $users, 'groups' => $groups]);
    }

    private static function store(): DashboardStore
    {
        try {
            $db = McpManifestLoader::load()->database();
        } catch (\Throwable) {
            $db = (string) (config('mad.mcp.database') ?: config('mad.general.main_database', 'business'));
        }

        return new DashboardStore($db);
    }
}
