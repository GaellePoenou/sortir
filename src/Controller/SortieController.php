<?php

namespace App\Controller;

use App\Data\SearchData;
use App\Entity\Participant;
use App\Entity\Sortie;
use App\Form\SearchForm;
use App\Form\SortieType;
use App\Repository\EtatRepository;
use App\Repository\ParticipantRepository;
use App\Repository\SortieRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Security;

#[Route('/sortie', name: 'sortie_')]
class SortieController extends AbstractController
{
    #[Route('/', name: 'creer')]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        ParticipantRepository $participantRepository,
        EtatRepository $etatRepository
    ): Response {
        /*récupération d'un participant et un campus - A RECUPERER AVEC LA SESSION*/
        $organisateur = $participantRepository->find(10);

        /*l'état est 'Créée' si validation avec 'Enregistrer', sinon il est 'Ouverte'*/
        //decaler la récupération de l'état après handlerequest ?
        $etat = $etatRepository->find(10);

        $sortie = new Sortie();
        $sortie->setEtat($etat);
        $sortie->setOrganisateur($organisateur);
        $sortie->setCampus($organisateur->getCampus());

        $sortieForm = $this->createForm(SortieType::class, $sortie);

        $sortieForm->get('campus')->setData($organisateur->getCampus()->getNom());
        $sortieForm->get('dateLimiteInscription')->setData(new \DateTimeImmutable('+2 days'));
        /*MARCHE PAS :
        $sortieForm->setData([
            'campus' => $organisateur->getCampus()->getNom(),
            'dateLimiteInscription' => new \DateTimeImmutable('+2 days')
        ]);*/

        $sortieForm->handleRequest($request);

        if ($sortieForm->isSubmitted() && $sortieForm->isValid()) {
            $entityManager->persist($sortie);
            $entityManager->flush();
            $this->addFlash('success', 'La sortie a bien été ajoutée !');
            return $this->redirectToRoute('home');
        }

        return $this->render('sortie/create.html.twig', [
            'sortieForm' => $sortieForm->createView()
        ]);
    }
    #[Route('/liste', name: 'liste')]
    public function liste(SortieRepository $sortieRepository, Request $request, Security $security): Response
    {
        $nombreParticipantsInscrits = $sortieRepository->participantsInscritsCounts();


        $data = new SearchData();
        $form = $this->createForm(SearchForm::class, $data);
        $form->handleRequest($request);

        $utilisateurConnecte = $this->getUser();
        $data->utilisateurInscrit = $utilisateurConnecte->getId();

        $sorties = $sortieRepository->findSearch($data);

        return $this->render('sortie/liste.html.twig', [
            'sorties' => $sorties,
            'form' => $form->createView(),
            'nombreParticipantsInscrits' => $nombreParticipantsInscrits,
            'utilisateurConnecte' => $utilisateurConnecte,
        ]);
    }
    #[Route('/show/{id}', name: 'detail')]
    public function show(int $id, SortieRepository $sortieRepository): Response
    {
        $sortie = $sortieRepository->find($id);
        $participant = $this->getUser();

        return $this->render('sortie/show.html.twig', [
            'sortie' => $sortie,
            'participant' => $participant,
        ]);
    }

    #[Route('/inscription/{id}', name: 'inscription')]
    public function inscription(Sortie $sortie, EntityManagerInterface $manager, EtatRepository $etat): RedirectResponse
    {
        $participant = $this->getUser();
        if (!$participant) {
            return $this->redirectToRoute('app_login');
        }

        $etatOuverte = $etat->findOneBy(['libelle' => 'Ouverte']);
        if (
            !$etatOuverte ||
            $sortie->getEtat() !== $etatOuverte ||
            new \DateTime() > $sortie->getDateLimiteInscription() ||
            $sortie->getParticipants()->count() >= $sortie->getNbInscriptionsMax()
        ) {
            return $this->redirectToRoute('sortie_liste');
        }

        $sortie->addParticipant($participant);

        $manager->flush();
        return $this->redirectToRoute('sortie_liste', ['id' => $sortie->getId()]);
    }

    #[Route('/desistement/{id}', name: 'desistement')]
    public function desister(Sortie $sortie, EntityManagerInterface $manager): Response
    {
        $participant = $this->getUser();

        $now = new \DateTime();

        if ($sortie->getDateHeureDebut() > $now && $sortie->getEtat()->getLibelle() === 'Ouverte') {
            $sortie->removeParticipant($participant);
            $manager->flush();

            $this->addFlash(
                'success',
                'Vous vous êtes désisté de la sortie avec succès.'
            );
        } else {
            $this->addFlash(
                'warning',
                'Vous ne pouvez pas vous désister de cette sortie.'
            );
        }

        return $this->redirectToRoute('sortie_liste', ['id' => $sortie->getId()]);
    }
}
