<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\Security\DeuxFacteurs\CodeIncorrect;
use App\Security\DeuxFacteurs\EnrolementInvalide;
use App\Security\DeuxFacteurs\EnrolementSecondFacteur;
use App\Security\DeuxFacteurs\GenerateurSecretTotp;
use App\Security\User;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Enrôlement du second facteur (#312) : le QR à scanner, puis la confirmation.
 *
 * L'écran n'est atteignable qu'avec le lien émis en ligne de commande sur le VPS,
 * **et** avec une session d'administration — le sous-domaine `admin.` exige déjà
 * ROLE_ADMIN. Il faut donc le lien et le mot de passe : un lien resté dans un
 * historique de terminal n'ouvre rien à lui seul.
 */
final class EnrolementSecondFacteurController extends AbstractController
{
    #[Route('/second-facteur/enrolement', name: 'second_facteur_enrolement', host: 'admin.%app.domaine%', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        EnrolementSecondFacteur $enrolement,
        GenerateurSecretTotp $secrets,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        $jeton = (string) $request->query->get('jeton', '');

        try {
            $facteur = $enrolement->secretAPresenter($jeton);
        } catch (EnrolementInvalide) {
            // Un seul rendu pour tous les motifs — inconnu, expiré, déjà consommé.
            throw $this->createNotFoundException();
        }

        if (!$request->isMethod('POST')) {
            return $this->render('admin/second_facteur_enrolement.html.twig', $this->ecran($user, $facteur->secret(), $secrets, $jeton));
        }

        try {
            $codes = $enrolement->confirmer($jeton, trim((string) $request->request->get('code', '')));
        } catch (CodeIncorrect) {
            return $this->render(
                'admin/second_facteur_enrolement.html.twig',
                // array_merge et non « + » : l'union de tableaux garde la valeur de
                // GAUCHE pour une clé présente des deux côtés, et « erreur » serait
                // resté à null.
                array_merge($this->ecran($user, $facteur->secret(), $secrets, $jeton), [
                    'erreur' => 'Code incorrect — vérifiez l\'heure de votre téléphone et réessayez.',
                ]),
                new Response('', Response::HTTP_UNPROCESSABLE_ENTITY),
            );
        }

        // Seul moment où les codes de secours existent en clair : ils sont hachés
        // en base, personne ne pourra les réafficher.
        return $this->render('admin/second_facteur_active.html.twig', ['codes' => $codes]);
    }

    /**
     * @return array{secret: string, secretGroupe: string, qr: string, jeton: string, erreur: null}
     */
    private function ecran(User $user, string $secret, GenerateurSecretTotp $secrets, string $jeton): array
    {
        $uri = $secrets->uriOtpauth($secret, $user->getEmail(), 'Valeur Verte');

        return [
            'secret' => $secret,
            // Groupé par quatre : il sera recopié à la main par qui ne scanne pas.
            'secretGroupe' => trim(chunk_split($secret, 4, ' ')),
            'qr' => (new Builder(writer: new SvgWriter(), data: $uri))->build()->getString(),
            'jeton' => $jeton,
            'erreur' => null,
        ];
    }
}
