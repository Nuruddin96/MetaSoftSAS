<?php

namespace App\Services\AI\Tools;

/**
 * Extended contract for a tool that mutates data (isMutating() === true).
 * handle() — inherited from AiTool — performs the actual mutation and
 * must ONLY ever be called after a pending action has been explicitly
 * confirmed by the user (see AiPendingAction / Tenant\AiChatController::confirm());
 * the AI-mediated tool-calling loop (AiChatService) never calls handle()
 * on a mutating tool directly. Instead it calls preview() via
 * AiToolRegistry::propose(), which validates the arguments and returns a
 * human-readable summary plus the fully-resolved arguments to store —
 * without performing any mutation.
 *
 * Read-only tools (App\Services\AI\Tools\OrderLookupTool and friends)
 * deliberately do NOT implement this — see AiToolRegistry::propose()'s
 * guard against calling it for a plain AiTool.
 */
interface AiMutatingTool extends AiTool
{
    /**
     * Validates $args and resolves everything handle() will need (e.g.
     * turning a product/variant NAME the AI supplied into a real,
     * tenant-scoped variant_id, checking current stock) — without
     * performing the mutation itself.
     *
     * @return array{summary: string, resolved_args: array}|array{error: string}
     *                                                                           'error' means the proposed action is invalid/not possible as
     *                                                                           described (e.g. product not found, insufficient stock) — this is
     *                                                                           a normal, expected outcome to show the user, not a thrown
     *                                                                           exception. 'resolved_args' becomes the ONLY input handle() ever
     *                                                                           receives later — never the raw $args this method was called
     *                                                                           with, and never anything a confirm request itself supplies.
     */
    public function preview(int $tenantId, array $args): array;
}
