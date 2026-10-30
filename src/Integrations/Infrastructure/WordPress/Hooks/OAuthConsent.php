<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\WordPress\Hooks;

use OrillaCart\Integrations\Application\Dto\AuthorizationRequest;
use OrillaCart\Integrations\Application\OAuthServer;
use OrillaCart\Integrations\Domain\OAuthError;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * The OAuth authorization endpoint: a screen in wp-admin where the shop manager, logged in as WordPress asks,
 * says whether an AI assistant's app may work in the shop as them, and whether it may change things or only read.
 * The request is checked before the screen is drawn: an error about the request goes back to the app's address,
 * one about the app or its address is shown here. The answer is a form with a nonce, sent to admin-post.php.
 *
 * The check runs on the screen's own load hook, which admin.php fires after the menu's capability check, and
 * only for a logged-in shop manager: anyone else sent here with a client of their own must not be redirected
 * on by the shop, nor make it fetch a client document (admin_init would run it for admin-ajax.php too, before
 * any login check).
 */
#[AsHook('admin_menu', method: 'register')]
#[AsHook(self::LOAD, method: 'check')]
#[AsHook('admin_post_' . self::ACTION, method: 'answer')]
#[AsHook('admin_title', method: 'title', filter: true)]
final class OAuthConsent
{
    public const string PAGE = 'orillacart-connect';
    public const string ACTION = 'orillacart_connect';
    /** The screen's load hook: load-{hook name of a submenu page without a parent}. */
    public const string LOAD = 'load-admin_page_' . self::PAGE;

    /** The OAuth parameters the screen carries through to the answer. */
    private const array PARAMETERS = ['response_type', 'client_id', 'redirect_uri', 'state', 'scope', 'code_challenge', 'code_challenge_method', 'resource'];

    private AuthorizationRequest|OAuthError|null $request = null;

    public function __construct(private readonly OAuthServer $server) {}

    public function register(): void
    {
        add_submenu_page('', __('Connect an app', 'orillacart'), '', Capability::MANAGE_SHOP, self::PAGE, $this->render(...));
    }

    /** A screen outside the menu has no title of its own. */
    public function title(mixed $title): mixed
    {
        return ($_GET['page'] ?? null) === self::PAGE && \is_string($title) ? __('Connect an app', 'orillacart') . $title : $title;
    }

    /** Before the screen is drawn, for the shop manager who may see it: a redirectable error goes back to the app now. */
    public function check(): void
    {
        if (!is_user_logged_in() || !current_user_can(Capability::MANAGE_SHOP) || ($_GET['page'] ?? null) !== self::PAGE) {
            return;
        }
        $this->request = $this->checked(self::parameters($_GET));
        if ($this->request instanceof OAuthError) {
            $back = $this->server->refusal($this->request);
            if ($back !== null) {
                wp_redirect($back);
                exit;
            }
        }
    }

    public function render(): void
    {
        $request = $this->request ?? $this->checked(self::parameters($_GET));
        echo '<div class="wrap orillacart-connect">';
        if ($request instanceof OAuthError) {
            printf('<h1>%s</h1><div class="notice notice-error"><p>%s</p></div></div>', esc_html__('This app cannot connect', 'orillacart'), esc_html($request->getMessage()));

            return;
        }
        $user = wp_get_current_user();
        $host = (string) (wp_parse_url($request->redirectUri, \PHP_URL_HOST) ?? wp_parse_url($request->redirectUri, \PHP_URL_SCHEME));
        printf('<h1>%s</h1>', esc_html(\sprintf(/* translators: %s: an app's name */ __('Connect %s to your shop', 'orillacart'), $request->client->name)));
        printf(
            '<p>%s</p><p>%s</p>',
            esc_html(\sprintf(
                /* translators: 1: an app's name, 2: the site's name, 3: a user's name */
                __('%1$s asks to work in %2$s as you, %3$s: to read the shop and, if you let it, to change it. Every change it makes is in the change log.', 'orillacart'),
                $request->client->name,
                get_bloginfo('name'),
                $user->display_name,
            )),
            esc_html(\sprintf(/* translators: %s: a web address's host, or an app's own scheme */ __('After you answer, you go back to: %s', 'orillacart'), $host)),
        );
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        printf('<input type="hidden" name="action" value="%s">', esc_attr(self::ACTION));
        wp_nonce_field(self::ACTION);
        foreach (self::parameters($_GET) as $name => $value) {
            printf('<input type="hidden" name="%s" value="%s">', esc_attr($name), esc_attr($value));
        }
        echo '<fieldset><legend class="screen-reader-text">' . esc_html__('It may', 'orillacart') . '</legend>';
        printf('<p><label><input type="radio" name="access" value="read"%s> %s</label></p>', checked(!$request->write, true, false), esc_html__('Only read: orders, products, customers, reports, settings', 'orillacart'));
        printf('<p><label><input type="radio" name="access" value="write"%s> %s</label></p>', checked($request->write, true, false), esc_html__('Read and change, as you could. It asks you first before money moves, something is deleted, customers hear of it, or the shop opens or closes.', 'orillacart'));
        echo '</fieldset>';
        printf('<p>%s</p>', esc_html__('You can change what it may do, or disconnect it, in Shop → Integrations.', 'orillacart'));
        printf(
            '<p><button type="submit" name="decision" value="approve" class="button button-primary">%s</button> <button type="submit" name="decision" value="deny" class="button">%s</button></p>',
            esc_html__('Connect', 'orillacart'),
            esc_html__('Do not connect', 'orillacart'),
        );
        echo '</form></div>';
    }

    /** The shop manager's answer: back to the app with a code, or with access_denied. */
    public function answer(): void
    {
        check_admin_referer(self::ACTION);
        if (!current_user_can(Capability::MANAGE_SHOP)) {
            wp_die(esc_html__('Only a shop manager can connect an app.', 'orillacart'), '', ['response' => 403]);
        }
        $request = $this->checked(self::parameters(wp_unslash($_POST)));
        if ($request instanceof OAuthError) {
            $back = $this->server->refusal($request);
            if ($back === null) {
                wp_die(esc_html($request->getMessage()), '', ['response' => 400]);
            }
            wp_redirect($back);
            exit;
        }
        $back = ($_POST['decision'] ?? null) === 'approve'
            ? $this->server->approve($request, get_current_user_id(), ($_POST['access'] ?? null) === 'write')
            : $this->server->deny($request);
        wp_redirect($back);
        exit;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function checked(array $parameters): AuthorizationRequest|OAuthError
    {
        try {
            return $this->server->authorization($parameters);
        } catch (OAuthError $e) {
            return $e;
        }
    }

    /**
     * The OAuth parameters of a request, as text.
     *
     * @return array<string, string>
     */
    private static function parameters(mixed $source): array
    {
        $parameters = [];
        foreach (self::PARAMETERS as $name) {
            $value = \is_array($source) ? ($source[$name] ?? null) : null;
            if (\is_string($value)) {
                $parameters[$name] = $value;
            }
        }

        return $parameters;
    }
}
