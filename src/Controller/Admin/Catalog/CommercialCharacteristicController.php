<?php

declare(strict_types=1);

namespace App\Controller\Admin\Catalog;

use App\Application\Catalog\CommercialCharacteristicData;
use App\Application\Catalog\CommercialCharacteristicManager;
use App\Application\Catalog\CommercialCharacteristicOptionData;
use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialCharacteristicOption;
use App\Entity\Users\User;
use App\Form\Admin\Catalog\CommercialCharacteristicOptionType;
use App\Form\Admin\Catalog\CommercialCharacteristicType;
use App\Repository\Catalog\CommercialCharacteristicOptionRepository;
use App\Repository\Catalog\CommercialCharacteristicRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/catalogo/caracteristicas', name: 'admin_catalog_characteristics_')]
final class CommercialCharacteristicController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, CommercialCharacteristicRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('catalog.view');

        $status = $request->query->getString('status', 'active');
        if (!in_array($status, ['active', 'inactive', 'all'], true)) {
            $status = 'active';
        }

        return $this->render('admin/catalog/characteristics/index.html.twig', [
            'page' => $repository->paginateForAdministration(
                search: $request->query->getString('q'),
                isActive: match ($status) {
                    'active' => true,
                    'inactive' => false,
                    default => null,
                },
                page: $request->query->getInt('page', 1),
            ),
            'search' => $request->query->getString('q'),
            'status' => $status,
        ]);
    }

    #[Route('/nueva', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, CommercialCharacteristicManager $manager): Response
    {
        $this->denyAccessUnlessGranted('catalog.characteristics.manage');

        $data = new CommercialCharacteristicData();
        $form = $this->createForm(CommercialCharacteristicType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $manager->create($data, $this->getActor());
            $this->addFlash('success', 'Característica registrada correctamente.');

            return $this->redirectToRoute('admin_catalog_characteristics_index');
        }

        return $this->render('admin/catalog/characteristics/form.html.twig', [
            'form' => $form,
            'characteristic' => null,
            'pageTitle' => 'Nueva característica',
        ]);
    }

    #[Route('/{id}/editar', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        CommercialCharacteristic $characteristic,
        CommercialCharacteristicManager $manager,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.characteristics.manage');

        $data = new CommercialCharacteristicData();
        $data->id = $characteristic->getId();
        $data->code = $characteristic->getCode();
        $data->name = $characteristic->getName();
        $data->inputType = $characteristic->getInputType();
        $data->unitLabel = $characteristic->getUnitLabel();
        $data->displayOrder = $characteristic->getDisplayOrder();

        $form = $this->createForm(CommercialCharacteristicType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $manager->update($characteristic, $data, $this->getActor());
                $this->addFlash('success', 'Característica actualizada correctamente.');

                return $this->redirectToRoute('admin_catalog_characteristics_index');
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->render('admin/catalog/characteristics/form.html.twig', [
            'form' => $form,
            'characteristic' => $characteristic,
            'pageTitle' => 'Editar característica',
        ]);
    }

    #[Route('/{id}/estado', name: 'status', methods: ['POST'])]
    public function status(
        Request $request,
        CommercialCharacteristic $characteristic,
        CommercialCharacteristicManager $manager,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.characteristics.manage');
        $this->assertCsrf('catalog_characteristic_status_'.$characteristic->getId(), $request);

        try {
            $manager->setActive($characteristic, !$characteristic->isActive(), $this->getActor());
            $this->addFlash('success', $characteristic->isActive()
                ? 'Característica reactivada correctamente.'
                : 'Característica desactivada correctamente.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_catalog_characteristics_index', $request->query->all());
    }

    #[Route('/{id}/opciones', name: 'options', methods: ['GET'])]
    public function options(
        CommercialCharacteristic $characteristic,
        CommercialCharacteristicOptionRepository $optionRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.view');
        $this->assertSupportsOptions($characteristic);

        return $this->render('admin/catalog/characteristics/options.html.twig', [
            'characteristic' => $characteristic,
            'options' => $optionRepository->findForCharacteristic($characteristic),
        ]);
    }

    #[Route('/{id}/opciones/nueva', name: 'option_new', methods: ['GET', 'POST'])]
    public function newOption(
        Request $request,
        CommercialCharacteristic $characteristic,
        CommercialCharacteristicManager $manager,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.characteristics.manage');
        $this->assertSupportsOptions($characteristic);

        $data = new CommercialCharacteristicOptionData();
        $data->characteristic = $characteristic;
        $form = $this->createForm(CommercialCharacteristicOptionType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $manager->createOption($characteristic, $data, $this->getActor());
            $this->addFlash('success', 'Opción registrada correctamente.');

            return $this->redirectToRoute('admin_catalog_characteristics_options', ['id' => $characteristic->getId()]);
        }

        return $this->render('admin/catalog/characteristics/option_form.html.twig', [
            'form' => $form,
            'characteristic' => $characteristic,
            'option' => null,
            'pageTitle' => 'Nueva opción',
        ]);
    }

    #[Route('/{id}/opciones/{option}/editar', name: 'option_edit', methods: ['GET', 'POST'])]
    public function editOption(
        Request $request,
        CommercialCharacteristic $characteristic,
        CommercialCharacteristicOption $option,
        CommercialCharacteristicManager $manager,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.characteristics.manage');
        $this->assertOptionBelongsToCharacteristic($option, $characteristic);

        $data = new CommercialCharacteristicOptionData();
        $data->id = $option->getId();
        $data->characteristic = $characteristic;
        $data->code = $option->getCode();
        $data->name = $option->getName();
        $data->displayOrder = $option->getDisplayOrder();
        $form = $this->createForm(CommercialCharacteristicOptionType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $manager->updateOption($option, $data, $this->getActor());
            $this->addFlash('success', 'Opción actualizada correctamente.');

            return $this->redirectToRoute('admin_catalog_characteristics_options', ['id' => $characteristic->getId()]);
        }

        return $this->render('admin/catalog/characteristics/option_form.html.twig', [
            'form' => $form,
            'characteristic' => $characteristic,
            'option' => $option,
            'pageTitle' => 'Editar opción',
        ]);
    }

    #[Route('/{id}/opciones/{option}/estado', name: 'option_status', methods: ['POST'])]
    public function optionStatus(
        Request $request,
        CommercialCharacteristic $characteristic,
        CommercialCharacteristicOption $option,
        CommercialCharacteristicManager $manager,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.characteristics.manage');
        $this->assertOptionBelongsToCharacteristic($option, $characteristic);
        $this->assertCsrf('catalog_characteristic_option_status_'.$option->getId(), $request);

        try {
            $manager->setOptionActive($option, !$option->isActive(), $this->getActor());
            $this->addFlash('success', $option->isActive()
                ? 'Opción reactivada correctamente.'
                : 'Opción desactivada correctamente.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_catalog_characteristics_options', ['id' => $characteristic->getId()]);
    }

    private function assertSupportsOptions(CommercialCharacteristic $characteristic): void
    {
        if (!$characteristic->getInputType()->supportsOptions()) {
            throw $this->createNotFoundException('Esta característica no utiliza opciones catalogadas.');
        }
    }

    private function assertOptionBelongsToCharacteristic(
        CommercialCharacteristicOption $option,
        CommercialCharacteristic $characteristic,
    ): void {
        if ($option->getCharacteristic()->getId() !== $characteristic->getId()) {
            throw $this->createNotFoundException();
        }
    }

    private function assertCsrf(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }
    }

    private function getActor(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
