<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\NotifyType;
use App\Form\SignInType;
use App\Security\DemoAuthenticator;
use Doctrine\DBAL\Connection;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\NotificationRequest;
use Nowo\NotifyKitBundle\Contract\NotifierInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Throwable;

/**
 * S2 demo: sign in as Alice or Bob, send yourself notifications and watch the header bell, the inbox page
 * (/notifications) and, as Alice, the operator page (/admin/notifications). Stateless (worker mode).
 */
final class DemoController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly NotifierInterface $notifier,
        #[Autowire('%nowo_notify_kit.realtime_mode%')] private readonly string $realtime,
        #[Autowire(service: 'security.user.provider.concrete.demo_users')] private readonly UserProviderInterface $people,
    ) {
    }

    #[Route('/', name: 'demo_home', methods: ['GET', 'POST'])]
    public function home(Request $request, Security $security): Response
    {
        $signIn = $this->createForm(SignInType::class);
        $signIn->handleRequest($request);
        if ($signIn->isSubmitted() && $signIn->isValid()) {
            /** @var array{person: string} $data */
            $data = $signIn->getData();
            $security->login($this->people->loadUserByIdentifier($data['person']), DemoAuthenticator::class, 'main');

            return $this->redirectToRoute('demo_home');
        }

        $notify = $this->createForm(NotifyType::class, null, ['action' => $this->generateUrl('demo_notify')]);
        try {
            $database = (string) $this->connection->fetchOne('SELECT VERSION()');
        } catch (Throwable) {
            $database = null;
        }

        return $this->render('demo/home.html.twig', [
            'sign_in'  => $signIn->createView(),
            'notify'   => $notify->createView(),
            'database' => $database,
            'realtime' => $this->realtime,
            'worker'   => ($_SERVER['FRANKENPHP_WORKER'] ?? null) !== null,
        ]);
    }

    #[Route('/demo/notify', name: 'demo_notify', methods: ['POST'])]
    public function notify(Request $request): Response
    {
        $user = $this->getUser();
        $form = $this->createForm(NotifyType::class);
        $form->handleRequest($request);
        if ($user === null || !$form->isSubmitted() || !$form->isValid()) {
            return $this->redirectToRoute('demo_home');
        }
        $me    = new RecipientRef('user', $user->getUserIdentifier());
        $other = new RecipientRef('user', $me->id === 'alice' ? 'bob' : 'alice');
        /** @var array{kind: string} $data */
        $data   = $form->getData();
        $result = $data['kind'] === 'project'
            ? $this->notifier->notify(new NotificationRequest('demo.project_update', [new RecipientRef('user', 'alice'), new RecipientRef('user', 'bob')], new RecipientRef('user', 'carol'), new ContextRef('project', 'demo'), 'project:demo', '/notifications', ['project' => 'demo']))
            : $this->notifier->notify(new NotificationRequest('demo.welcome', [$me], $other, null, 'welcome:' . $me->id, '/notifications'));
        $this->addFlash('success', \sprintf('notify(): %d written, %d coalesced, %d skipped (access), %d skipped (actor).', $result->written, $result->coalesced, $result->skippedAccess, $result->skippedActor));

        return $this->redirectToRoute('demo_home');
    }

    #[Route('/logout', name: 'demo_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Handled by the firewall.');
    }
}
