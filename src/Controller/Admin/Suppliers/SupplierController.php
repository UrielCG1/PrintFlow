<?php

namespace App\Controller\Admin\Suppliers;

use App\Application\Suppliers\SupplierData;
use App\Application\Suppliers\SupplierManager;
use App\Entity\Suppliers\Supplier;
use App\Entity\Users\User;
use App\Form\Admin\Suppliers\SupplierType;
use App\Repository\Suppliers\SupplierRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/proveedores', name: 'admin_suppliers_')]
final class SupplierController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, SupplierRepository $supplierRepository): Response
    {
        $this->denyAccessUnlessGranted('suppliers.view');

        $status = $request->query->getString('status', 'active');

        if (!in_array($status, ['active', 'inactive', 'all'], true)) {
            $status = 'active';
        }

        $isActive = match ($status) {
            'active' => true,
            'inactive' => false,
            default => null,
        };

        return $this->render('admin/suppliers/index.html.twig', [
            'page' => $supplierRepository->paginateForAdministration(
                search: $request->query->getString('q'),
                isActive: $isActive,
                page: $request->query->getInt('page', 1),
            ),
            'search' => $request->query->getString('q'),
            'status' => $status,
        ]);
    }

    #[Route('/nuevo', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, SupplierManager $supplierManager): Response
    {
        $this->denyAccessUnlessGranted('suppliers.create');

        $data = new SupplierData();
        $form = $this->createForm(SupplierType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $supplierManager->create($data, $this->getActor());
            } catch (UniqueConstraintViolationException) {
                $form->get('code')->addError(
                    new FormError('El código o RFC ya está registrado para otro proveedor.'),
                );

                return $this->render('admin/suppliers/form.html.twig', [
                    'form' => $form,
                    'supplier' => null,
                    'pageTitle' => 'Nuevo proveedor',
                ]);
            }

            $this->addFlash('success', 'Proveedor registrado correctamente.');

            return $this->redirectToRoute('admin_suppliers_index');
        }

        return $this->render('admin/suppliers/form.html.twig', [
            'form' => $form,
            'supplier' => null,
            'pageTitle' => 'Nuevo proveedor',
        ]);
    }

    #[Route('/{id}/editar', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(
        Request $request,
        Supplier $supplier,
        SupplierManager $supplierManager,
    ): Response {
        $this->denyAccessUnlessGranted('suppliers.update');

        $data = new SupplierData();
        $data->id = $supplier->getId();
        $data->code = $supplier->getCode();
        $data->businessName = $supplier->getBusinessName();
        $data->legalName = $supplier->getLegalName();
        $data->taxId = $supplier->getTaxId();
        $data->email = $supplier->getEmail();
        $data->phone = $supplier->getPhone();
        $data->notes = $supplier->getNotes();

        $form = $this->createForm(SupplierType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $supplierManager->update($supplier, $data, $this->getActor());
            } catch (UniqueConstraintViolationException) {
                $form->get('code')->addError(
                    new FormError('El código o RFC ya está registrado para otro proveedor.'),
                );

                return $this->render('admin/suppliers/form.html.twig', [
                    'form' => $form,
                    'supplier' => $supplier,
                    'pageTitle' => 'Editar proveedor',
                ]);
            }

            $this->addFlash('success', 'Proveedor actualizado correctamente.');

            return $this->redirectToRoute('admin_suppliers_index');
        }

        return $this->render('admin/suppliers/form.html.twig', [
            'form' => $form,
            'supplier' => $supplier,
            'pageTitle' => 'Editar proveedor',
        ]);
    }

    #[Route('/{id}/estado', name: 'status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function status(
        Request $request,
        Supplier $supplier,
        SupplierManager $supplierManager,
    ): Response {
        $this->denyAccessUnlessGranted('suppliers.toggle_status');

        if (!$this->isCsrfTokenValid(
            'supplier_status_'.$supplier->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        $supplierManager->setActive($supplier, !$supplier->isActive(), $this->getActor());

        $this->addFlash(
            'success',
            $supplier->isActive()
                ? 'Proveedor reactivado correctamente.'
                : 'Proveedor desactivado correctamente.',
        );

        return $this->redirectToRoute('admin_suppliers_index', $request->query->all());
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