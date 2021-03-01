<?php

namespace App\Controller;

use App\Entity\OfficerNote;
use App\Exception\NotFoundException;
use App\Form\Governor\SetAllianceType;
use App\Form\OfficerNote\AddOfficerNoteType;
use App\Service\Governor\GovernorDetailsService;
use App\Service\Governor\GovernorManagementService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/g")
 */
class GovernorController extends AbstractController
{
    private $govManagementService;
    private $detailsService;

    public function __construct(
        GovernorManagementService $governorManagementService,
        GovernorDetailsService $detailsService
    )
    {
        $this->govManagementService = $governorManagementService;
        $this->detailsService = $detailsService;
    }

    /**
     * @Route("/{id}", name="governor", methods={"GET"})
     * @param string $id
     * @return Response
     */
    public function index(string $id): Response
    {
        try {
            $gov = $this->govManagementService->findGov($id);
        } catch (NotFoundException $e) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        return $this->render('governor/index.html.twig', [
            'gov' => $this->detailsService->createGovernorDetails($gov, $this->getUser()),
        ]);
    }

    /**
     * @Route("/{id}/note", name="governor_add_note", methods={"GET", "POST"})
     * @IsGranted("ROLE_OFFICER")
     * @param string $id
     * @param Request $request
     * @return Response
     */
    public function note(string $id, Request $request): Response
    {
        try {
            $gov = $this->govManagementService->findGov($id);
        } catch (NotFoundException $e) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $form = $this->createForm(AddOfficerNoteType::class, new OfficerNote());
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->govManagementService->addOfficerNote($gov, $form->getData(), $this->getUser());

            return $this->redirectToRoute('governor', ['id' => $gov->getGovernorId()]);
        }

        return $this->render('governor/add_note.html.twig' , [
            'form' => $form->createView(),
            'gov' => $this->detailsService->createGovernorDetails($gov, $this->getUser()),
        ]);
    }

    /**
     * @Route("/{id}/alliance", name="governor_set_alliance", methods={"GET", "POST"})
     * @IsGranted("ROLE_OFFICER")
     * @param string $id
     * @param Request $request
     * @return Response
     */
    public function setAlliance(string $id, Request $request): Response
    {
        try {
            $gov = $this->govManagementService->findGov($id);
        } catch (NotFoundException $e) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $form = $this->createForm(SetAllianceType::class, $gov);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->govManagementService->save($gov);

            return $this->redirectToRoute('governor', ['id' => $gov->getGovernorId()]);
        }

        return $this->render('governor/edit_alliance.html.twig' , [
            'form' => $form->createView(),
            'gov' => $this->detailsService->createGovernorDetails($gov, $this->getUser()),
        ]);
    }
}
