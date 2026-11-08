<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * Ready-to-paste client configuration for the assistant connection, shown
 * on the API token page (with the fresh token filled in once):
 *  - Claude Code: one `claude mcp add` command (HTTP transport, bearer header);
 *  - Claude Desktop: claude_desktop_config.json entry through `mcp-remote`
 *    (Desktop speaks stdio; mcp-remote bridges to /mcp);
 *  - project file `.mcp.json` for Claude Code with the token from an
 *    environment variable, so it can be committed without the secret.
 */
final class McpClientConfig
{
    public const string SERVER_KEY = 'isms';
    public const string TOKEN_PLACEHOLDER = '<TOKEN>';

    /** @return array<string, string> key => snippet */
    public static function snippets(string $endpoint, ?string $token): array
    {
        $token = $token !== null && $token !== '' ? $token : self::TOKEN_PLACEHOLDER;
        $json = static fn (array $data): string => (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'claude_code' => sprintf(
                'claude mcp add --transport http %s %s --header "Authorization: Bearer %s"',
                self::SERVER_KEY,
                $endpoint,
                $token,
            ),
            'claude_desktop' => $json(['mcpServers' => [self::SERVER_KEY => [
                'command' => 'npx',
                'args' => ['-y', 'mcp-remote', $endpoint, '--header', 'Authorization:${ISMS_AUTH}'],
                'env' => ['ISMS_AUTH' => 'Bearer ' . $token],
            ]]]),
            'project_file' => $json(['mcpServers' => [self::SERVER_KEY => [
                'type' => 'http',
                'url' => $endpoint,
                'headers' => ['Authorization' => 'Bearer ${ISMS_TOKEN}'],
            ]]]),
        ];
    }
}
