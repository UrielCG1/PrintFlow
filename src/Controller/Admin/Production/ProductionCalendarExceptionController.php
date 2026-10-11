<?php

declare(strict_types=1);

namespace App\Controller\Admin\Production;

use App\Application\Production\ProductionCalendarExceptionData;
use App\Application\Production\ProductionCalendarExceptionManager;
use App\Entity\Production\ProductionCalendarException;
use App\Entity\Users\User;
use App\Form\Admin\Production\ProductionCalendarExceptionType;
use App\Repository\Equipment\EquipmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/produccion/jornada', name: 'admin_production_calendar_')]
final class ProductionCalendarExceptionController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('production_calendar.manage');
        $exceptions = $entityManager->getRepository(ProductionCalendarException::class)->findBy([], ['startsAt' => 'DESC']);
        return $this->render('admin/production/calendar/index.html.twig', ['exceptions' => $exceptions]);
    }

    #[Route('/nueva', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EquipmentRepository $equipmentRepository,
        ProductionCalendarExceptionManager $manager,
    ): Response {
        $this->denyAccessUnlessGranted('production_calendar.manage');
        $data = new ProductionCalendarExceptionData();
        $form = $this->createForm(ProductionCalendarExceptionType::class, $data, [
            'equipment_choices' => $equipmentRepository->findAllForCalendarException(),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $manager->create($data, $this->actor());
                $this->addFlash('success', 'La excepción de jornada quedó registrada y auditada.');
                return $this->redirectToRoute('admin_production_calendar_index');
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }
        return $this->render('admin/production/calendar/form.html.twig', ['form' => $form, 'pageTitle' => 'Nueva excepción de jornada']);
    }

    #[Route('/{id}/desactivar', name: 'deactivate', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function deactivate(
        Request $request,
        ProductionCalendarException $exception,
        ProductionCalendarExceptionManager $manager,
    ): Response {
        $this->denyAccessUnlessGranted('production_calendar.manage');
        if (!$this->isCsrfTokenValid('production-calendar-exception-deactivate-'.$exception->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }
        $manager->deactivate($exception, $this->actor());
        $this->addFlash('success', 'La excepción quedó desactivada; su historial se conservó.');
        return $this->redirectToRoute('admin_production_calendar_index');
    }

    private function actor(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) { throw $this->createAccessDeniedException(); }
        return $user;
    }
}
