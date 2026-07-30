<?php

declare(strict_types=1);

namespace App\Action;

use App\Entity\Feedback;
use App\Entity\User;
use App\Form\FeedbackType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
class FeedbackAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[Route(
        path: '/feedback',
        name: 'feedback',
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request): RedirectResponse|Response|array
    {
        $form = $this->createForm(FeedbackType::class);
        $form->handleRequest($request);
        $ajax = $request->isXmlHttpRequest();

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $user = $this->getUser();

            if (!$user instanceof User) {
                throw $this->createAccessDeniedException('User is required to submit feedback');
            }

            $feedback = new Feedback(
                $data['category'],
                $data['message'],
                $user,
            );

            $this->entityManager->persist($feedback);
            $this->entityManager->flush();

            // The game view's feedback modal (08-frontend.md-style AJAX flow,
            // matching the resign modal) posts here and stays in place; the
            // plain <form> fallback (feedback.html.twig, no JS) still redirects.
            if ($ajax) {
                return new JsonResponse(['success' => true]);
            }

            return $this->redirectToRoute('feedback', ['sent' => 1]);
        }

        $sent = $request->query->getBoolean('sent');

        if ($ajax) {
            return new Response($this->renderView('actions/_feedback_form.html.twig', [
                'form' => $form->createView(),
                'sent' => $sent,
            ]));
        }

        return [
            'form' => $form->createView(),
            'sent' => $sent,
        ];
    }
}
