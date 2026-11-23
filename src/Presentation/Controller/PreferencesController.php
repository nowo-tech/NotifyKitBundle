<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\Controller;

use JsonException;
use Nowo\NotifyKitBundle\Contract\ContextChoice;
use Nowo\NotifyKitBundle\Contract\ContextProviderInterface;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;
use Nowo\NotifyKitBundle\Contract\Exception\PreferenceConflictException;
use Nowo\NotifyKitBundle\Contract\PreferenceChange;
use Nowo\NotifyKitBundle\Contract\PreferenceChangeSet;
use Nowo\NotifyKitBundle\Contract\PreferenceExpectation;
use Nowo\NotifyKitBundle\Contract\PreferenceKey;
use Nowo\NotifyKitBundle\Contract\PreferencesInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\Scope;
use Nowo\NotifyKitBundle\Contract\Security\PreferencesAccessCheckerInterface;
use Nowo\NotifyKitBundle\Presentation\Form\ActionType;
use Nowo\NotifyKitBundle\Presentation\Form\PreferencesType;
use Nowo\NotifyKitBundle\Presentation\Http\Conflicts;
use Nowo\NotifyKitBundle\Presentation\Http\CurrentRecipient;
use Nowo\NotifyKitBundle\Presentation\Http\Responses;
use Nowo\NotifyKitBundle\Presentation\View\GridCell;
use Nowo\NotifyKitBundle\Presentation\View\GridRow;
use Nowo\NotifyKitBundle\Presentation\View\GridSwitch;
use Nowo\NotifyKitBundle\Presentation\View\PreferenceGrid;
use Nowo\NotifyKitBundle\Presentation\View\PreferencePages;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Label;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

use function array_is_list;
use function count;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function json_decode;
use function sprintf;
use function str_contains;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * The person's notification preferences (contract §7.1 `GET/POST /account/notifications`, §8.3), under
 * `web_ui.preferences_path`: the account page (master switch, category × channel matrix, scope per category, the
 * contexts with their switches), a context page (that context's overrides, also loaded into a dialog on demand), the
 * JSON autosave endpoint and *Reset to account settings*.
 *
 * Every action resolves the logged-in person from the token storage (none → the firewall's login answer) and checks
 * the preferences access checker (REQ-UI-002; refused → 403). There is no recipient parameter anywhere: a person only
 * ever reads and changes their own preferences. A context is accepted only when the context provider lists it for
 * this person (otherwise 404). POSTs need the CSRF token `nowo_notify_kit_preferences` (`X-CSRF-TOKEN` for `fetch`, the
 * form's `_token` otherwise; missing or wrong → 403). Saves are optimistic: a cell changed elsewhere since the page
 * showed it answers **409 + `CONFLICT_001`** with the current cells (nothing written); a lock conflict answers 409 +
 * `Retry-After` + `CONFLICT_001`. Answers are `no-store, private`.
 *
 * Stateless (worker mode): every value comes from the request and the ports.
 */
final readonly class PreferencesController
{
    /** Most changes one autosave request may carry. */
    public const MAX_CHANGES = 100;

    /** Largest autosave body, in bytes. */
    public const MAX_BODY = 32768;

    /**
     * @param list<string> $scopes `preferences.scopes`
     * @param string|null $template `web_ui.templates.preferences`
     */
    public function __construct(
        private PreferencesInterface $preferences,
        private ContextProviderInterface $contexts,
        private CurrentRecipient $current,
        private PreferencesAccessCheckerInterface $access,
        private PreferencePages $pages,
        private FormFactoryInterface $forms,
        private Environment $twig,
        private UrlGeneratorInterface $router,
        private CsrfTokenManagerInterface $csrf,
        private array $scopes = ['all', 'involved'],
        private ?string $template = null,
    ) {
    }

    /**
     * The account page.
     */
    #[Route('/', name: 'nowo_notify_kit_preferences', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $recipient = $this->recipient();
        $grid      = $this->grid($recipient, null);

        return $this->render($request, $grid, $this->form($grid, $this->router->generate('nowo_notify_kit_preferences_save')), $request->query->has('saved') ? 'saved' : null);
    }

    /**
     * Saves the account page without JavaScript: the cells that differ from what the page showed.
     */
    #[Route('/', name: 'nowo_notify_kit_preferences_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $recipient = $this->recipient();

        return $this->submit($request, $recipient, null, $this->router->generate('nowo_notify_kit_preferences'));
    }

    /**
     * A context page (`?key=<type>:<id>`): the context's overrides; with `fragment=1` only the grid (the dialog).
     */
    #[Route('/context', name: 'nowo_notify_kit_preferences_context', methods: ['GET'])]
    public function context(Request $request): Response
    {
        $recipient = $this->recipient();
        $context   = $this->contextOf($recipient, $request->query->get('key'));
        $grid      = $this->grid($recipient, $context);
        $form      = $this->form($grid, $this->router->generate('nowo_notify_kit_preferences_context_save', ['key' => $context->ref->key()]));

        return $this->render($request, $grid, $form, $request->query->has('saved') ? 'saved' : null, $context);
    }

    /**
     * Saves a context page without JavaScript.
     */
    #[Route('/context', name: 'nowo_notify_kit_preferences_context_save', methods: ['POST'])]
    public function contextSave(Request $request): Response
    {
        $recipient = $this->recipient();
        $context   = $this->contextOf($recipient, $request->query->get('key'));

        return $this->submit($request, $recipient, $context, $this->router->generate('nowo_notify_kit_preferences_context', ['key' => $context->ref->key()]));
    }

    /**
     * *Reset to account settings* of a context (`?key=`): deletes its overrides, keeps its switch.
     */
    #[Route('/context/reset', name: 'nowo_notify_kit_preferences_reset', methods: ['POST'])]
    public function reset(Request $request): RedirectResponse
    {
        $recipient = $this->recipient();
        $this->checkCsrf($request);
        $context = $this->contextOf($recipient, $request->query->get('key'));
        try {
            $this->preferences->resetContext($recipient, $context->ref);
        } catch (TransientStorageFailure $failure) {
            throw Conflicts::exception($failure);
        }

        return new RedirectResponse(Responses::redirectTarget($request, $this->router->generate('nowo_notify_kit_preferences_context', ['key' => $context->ref->key(), 'saved' => 1])));
    }

    /**
     * Autosave (JSON, `X-CSRF-TOKEN`): `{"changes": [{"context", "category", "channel", "enabled", "scope", "expected"}]}`.
     * Answers `{"ok": true, "cells": […]}` with the state of every requested cell; 409 `stale` with the current cells
     * when one changed meanwhile (nothing written); 422 for an invalid change; 404 for a context the person cannot
     * tune.
     */
    #[Route('/cells', name: 'nowo_notify_kit_preferences_cells', methods: ['POST'])]
    public function cells(Request $request): JsonResponse
    {
        $recipient = $this->recipient();
        $token     = $request->headers->get(Responses::CSRF_HEADER);
        if (!is_string($token) || !$this->csrf->isTokenValid(new CsrfToken(PreferencesType::CSRF_ID, $token))) {
            throw new AccessDeniedHttpException(sprintf('Invalid CSRF token (%s).', PreferencesType::CSRF_ID));
        }
        $changes = $this->decode($request, $recipient);
        if ($changes === null) {
            return Responses::noStore(new JsonResponse(['ok' => false, 'error' => 'invalid'], 422));
        }
        $keys = [];
        foreach ($changes as $change) {
            $keys[] = $change->key;
        }
        try {
            $this->preferences->save($recipient, new PreferenceChangeSet($changes));
        } catch (PreferenceConflictException) {
            return Responses::noStore(new JsonResponse(['ok' => false, 'error' => 'stale', 'code' => ConflictCode::RETRY_SAFE, 'cells' => $this->cellStates($recipient, $keys)], 409, ['X-Conflict-Code' => ConflictCode::RETRY_SAFE]));
        } catch (InvalidNotificationArgumentException) {
            return Responses::noStore(new JsonResponse(['ok' => false, 'error' => 'invalid'], 422));
        } catch (TransientStorageFailure $failure) {
            return Conflicts::json($failure);
        }

        return Responses::noStore(new JsonResponse(['ok' => true, 'cells' => $this->cellStates($recipient, $keys)]));
    }

    /**
     * The changes of an autosave body, or null when it is not valid. Contexts must be ones the person can tune
     * (otherwise 404).
     *
     * @return list<PreferenceChange>|null
     */
    private function decode(Request $request, RecipientRef $recipient): ?array
    {
        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY) {
            return null;
        }
        try {
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        $items = is_array($data) ? ($data['changes'] ?? null) : null;
        if (!is_array($items) || !array_is_list($items) || $items === [] || count($items) > self::MAX_CHANGES) {
            return null;
        }
        $contexts = null;
        $changes  = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['context'] ?? null) || !is_string($item['category'] ?? null) || !is_string($item['channel'] ?? null)
                || !is_bool($item['enabled'] ?? null) || !is_string($item['scope'] ?? null) || !is_string($item['expected'] ?? null)) {
                return null;
            }
            $scope = Scope::tryFrom($item['scope']);
            if (!$scope instanceof Scope) {
                return null;
            }
            $ref = null;
            if ($item['context'] !== '') {
                $contexts ??= $this->contexts->contextsFor($recipient);
                $ref = $this->find($contexts, $item['context'])?->ref;
                if (!$ref instanceof ContextRef) {
                    throw new NotFoundHttpException('Context not found.');
                }
            }
            try {
                $changes[] = new PreferenceChange(PreferenceKey::of($ref, $item['category'], $item['channel']), $item['enabled'], $scope, PreferenceExpectation::fromToken($item['expected']));
            } catch (InvalidNotificationArgumentException) {
                return null;
            }
        }

        return $changes;
    }

    /**
     * The current state of $keys for the script: the shown choice and the stored token.
     *
     * @param list<PreferenceKey> $keys
     *
     * @return list<array{context: string, category: string, channel: string, enabled: bool, scope: string, token: string}>
     */
    private function cellStates(RecipientRef $recipient, array $keys): array
    {
        $matrix = $this->preferences->matrixFor($recipient);
        $refs   = [];
        foreach ($keys as $key) {
            if (!$key->isAccount() && !isset($refs[$key->contextKey])) {
                $refs[$key->contextKey] = $this->find($this->contexts->contextsFor($recipient), $key->contextKey)?->ref;
            }
        }
        $states = [];
        foreach ($keys as $key) {
            $stored = $matrix->stored($key);
            $shown  = $stored ?? $matrix->defaultFor($key);
            if (!$key->isSwitch() && $key->channel !== PreferenceKey::ANY) {
                $shown = $matrix->cell($key->category, $key->channel, $refs[$key->contextKey] ?? null);
            }
            $states[] = [
                'context'  => $key->contextKey,
                'category' => $key->category,
                'channel'  => $key->channel,
                'enabled'  => $shown->enabled,
                'scope'    => $shown->scope->value,
                'token'    => PreferenceExpectation::tokenOf($stored),
            ];
        }

        return $states;
    }

    /**
     * A no-JS save: the cells whose submitted value differs from what the page showed, with the page's tokens.
     */
    private function submit(Request $request, RecipientRef $recipient, ?ContextChoice $context, string $back): Response
    {
        $grid = $this->grid($recipient, $context);
        $form = $this->form($grid, $request->getRequestUri());
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedHttpException(sprintf('Invalid preferences form or CSRF token (%s).', PreferencesType::CSRF_ID));
        }
        $changes = [];
        $ref     = $context?->ref;
        // Compared with what the page showed (its hidden v<field> values), not with the stored state now: a change made
        // elsewhere since then must be refused by the token, never silently skipped or overwritten.
        foreach ($grid->fields() as $name => [$field, $row]) {
            $checked = $form->get($name)->getData() === true;
            $shown   = $form->get('v' . $name)->getData() === '1';
            $token   = $form->get('k' . $name)->getData();
            $scope   = $row instanceof GridRow ? $this->scope($form, $row) : Scope::All;
            if ($field instanceof GridCell && $row instanceof GridRow) {
                $shownScope = $row->scopeField === null ? $row->scope : $form->get('v' . $row->scopeField)->getData();
                if ($checked === $shown && $scope->value === $shownScope) {
                    continue;
                }
                $key = PreferenceKey::of($ref, $row->category, $field->channel);
            } else {
                if ($checked === $shown || !$field instanceof GridSwitch) {
                    continue;
                }
                $switchContext = $field->contextKey === '' ? null : $this->find($this->contexts->contextsFor($recipient), $field->contextKey)?->ref;
                $key           = PreferenceKey::of($switchContext, PreferenceKey::ANY, PreferenceKey::ANY);
            }
            try {
                $changes[] = new PreferenceChange($key, $checked, $scope, PreferenceExpectation::fromToken(is_string($token) ? $token : ''));
            } catch (InvalidNotificationArgumentException) {
                throw new AccessDeniedHttpException('Invalid preferences form.');
            }
        }
        try {
            $this->preferences->save($recipient, new PreferenceChangeSet($changes));
        } catch (PreferenceConflictException) {
            $fresh = $this->grid($recipient, $context);

            return $this->render($request, $fresh, $this->form($fresh, $request->getRequestUri()), 'stale', $context, 409);
        } catch (InvalidNotificationArgumentException) {
            throw new AccessDeniedHttpException('Invalid preferences change.');
        } catch (TransientStorageFailure $failure) {
            throw Conflicts::exception($failure);
        }

        return new RedirectResponse($back . (str_contains($back, '?') ? '&' : '?') . 'saved=1');
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function scope(FormInterface $form, GridRow $row): Scope
    {
        if ($row->scopeField === null) {
            return Scope::tryFrom($row->scope) ?? Scope::All;
        }
        $value = $form->get($row->scopeField)->getData();
        $scope = is_string($value) ? Scope::tryFrom($value) : null;

        return $scope instanceof Scope && in_array($scope->value, $this->scopes, true) ? $scope : Scope::All;
    }

    private function grid(RecipientRef $recipient, ?ContextChoice $context): PreferenceGrid
    {
        $matrix   = $this->preferences->matrixFor($recipient, $context?->ref);
        $contexts = $context instanceof ContextChoice ? [] : $this->contexts->contextsFor($recipient);

        return $this->pages->grid($matrix, $context, $contexts, fn (ContextChoice $choice): string => $this->router->generate('nowo_notify_kit_preferences_context', ['key' => $choice->ref->key()]));
    }

    /**
     * @return FormInterface<mixed>
     */
    private function form(PreferenceGrid $grid, string $action): FormInterface
    {
        return $this->forms->createNamed($grid->contextKey === '' ? 'nkp' : 'nkc', PreferencesType::class, null, ['grid' => $grid, 'action' => $action]);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function render(Request $request, PreferenceGrid $grid, FormInterface $form, ?string $notice, ?ContextChoice $context = null, int $status = 200): Response
    {
        $fragment = $context instanceof ContextChoice && $request->query->getBoolean('fragment');
        $actions  = [];
        if ($context instanceof ContextChoice) {
            $back      = $this->router->generate('nowo_notify_kit_preferences');
            $resetForm = $this->forms->createNamed('', ActionType::class, null, ['csrf_token_id' => PreferencesType::CSRF_ID, 'redirect' => $this->router->generate('nowo_notify_kit_preferences_context', ['key' => $context->ref->key(), 'saved' => 1])]);
            $actions   = [
                new Action('reset', new Label('notify.preferences.reset', PreferencePages::DOMAIN), $this->router->generate('nowo_notify_kit_preferences_reset', ['key' => $context->ref->key()]), method: 'POST', attributes: ['data-testid' => 'notify-kit-preferences-reset'], form: $resetForm->createView()),
                new Action('prev', new Label('notify.preferences.back', PreferencePages::DOMAIN), $back, attributes: ['data-testid' => 'notify-kit-preferences-back']),
            ];
        }
        $view = $form->createView();
        $vars = [
            'page'      => $this->pages->page($grid, $view, $actions),
            'grid'      => $grid,
            'form'      => $view,
            'notice'    => $notice,
            'cells_url' => $this->router->generate('nowo_notify_kit_preferences_cells'),
            'csrf'      => $this->csrf->getToken(PreferencesType::CSRF_ID)->getValue(),
            'fragment'  => $fragment,
        ];
        $template = $fragment ? '@NowoNotifyKitBundle/preferences/_fragment.html.twig' : ($this->template ?? '@NowoNotifyKitBundle/preferences/index.html.twig');

        return Responses::noStore(new Response($this->twig->render($template, $vars), $status));
    }

    /**
     * The context $key among the contexts the provider lists for $recipient; 404 otherwise.
     */
    private function contextOf(RecipientRef $recipient, mixed $key): ContextChoice
    {
        $choice = is_string($key) ? $this->find($this->contexts->contextsFor($recipient), $key) : null;
        if (!$choice instanceof ContextChoice) {
            throw new NotFoundHttpException('Context not found.');
        }

        return $choice;
    }

    /**
     * @param list<ContextChoice> $contexts
     */
    private function find(array $contexts, string $key): ?ContextChoice
    {
        foreach ($contexts as $choice) {
            if ($choice->ref->key() === $key) {
                return $choice;
            }
        }

        return null;
    }

    /**
     * The logged-in person: none → the firewall's login answer (401 / 302); refused by the access checker → 403.
     */
    private function recipient(): RecipientRef
    {
        $user = $this->current->user();
        if ($user === null) {
            throw new AccessDeniedException('Log in to change your notification preferences.');
        }
        $recipient = $this->current->recipientOf($user);
        if (!$recipient instanceof RecipientRef || !$this->access->canAccess($user)) {
            throw new AccessDeniedHttpException('No access to the notification preferences.');
        }

        return $recipient;
    }

    /**
     * CSRF of a no-JS POST (the `_token` of the ActionType form); 403 otherwise.
     */
    private function checkCsrf(Request $request): void
    {
        $form = $this->forms->createNamed('', ActionType::class, null, ['csrf_token_id' => PreferencesType::CSRF_ID]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedHttpException(sprintf('Invalid CSRF token (%s).', PreferencesType::CSRF_ID));
        }
    }
}
