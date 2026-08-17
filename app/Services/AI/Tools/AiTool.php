<?php

namespace App\Services\AI\Tools;

/**
 * Contract every predefined AI tool must satisfy — see req. #19/#20 in the
 * AI Agent audit: the AI may only ever act through one of these, never via
 * direct/raw database access. AiToolRegistry is the sole caller of
 * handle(); nothing else should invoke a tool directly, so the tenant/
 * security discipline described below stays centralized in one place.
 */
interface AiTool
{
    /** Unique tool name — the OpenAI function-calling name and the AiToolRegistry lookup key. */
    public function name(): string;

    /** Human-readable description shown to the AI (what this tool does, when to use it). */
    public function description(): string;

    /**
     * JSON-schema-shaped array — becomes this tool's OpenAI function-
     * calling 'parameters' field verbatim (see AiToolRegistry::toOpenAiSchema()).
     * MUST NEVER declare a tenant_id/tenant_slug/currentTenant property —
     * tenant identity is never AI-supplied, see handle()'s docblock and
     * AiToolRegistry::call(), which strips any such key defensively even
     * if one somehow appeared in the arguments.
     */
    public function parametersSchema(): array;

    /**
     * True for a tool that only reads data (order/product/customer
     * lookup, sales report); false for anything that creates/updates/
     * deletes. AiToolRegistry uses this to decide which tools are safe to
     * expose without the (not-yet-built) confirmation-system gate —
     * every Phase 3 tool is read-only, so this is always true today, but
     * the distinction is load-bearing information about the tool itself,
     * not a speculative addition.
     */
    public function isMutating(): bool;

    /**
     * Executes the tool for a specific, server-verified tenant.
     *
     * $tenantId is ALWAYS supplied by the trusted caller (AiToolRegistry,
     * which itself only ever receives it from server-side code — e.g. the
     * currently-authenticated tenant, or a queued job's own tenant_id
     * property — never from anything the AI or a customer said). $args
     * comes from the AI's function-call arguments (or a direct
     * programmatic caller bypassing the AI entirely — see
     * AiToolRegistry's docblock on deterministic execution) and MUST NOT
     * be trusted to identify a tenant: implementations must ignore any
     * tenant_id/tenant_slug-shaped key inside $args even if present, and
     * must scope every query explicitly by the $tenantId parameter (never
     * by an ambient app('currentTenant') binding, which may not exist in
     * the context this runs from — see ProcessAiAgentMessage for the
     * established pattern this follows).
     *
     * @return array Structured result data — plain, JSON-serializable
     *               (safe to send back to the AI as the tool's output),
     *               and equally safe to consume directly by deterministic,
     *               non-AI-mediated Laravel code with no OpenAI call
     *               involved at all.
     */
    public function handle(int $tenantId, array $args): array;
}
