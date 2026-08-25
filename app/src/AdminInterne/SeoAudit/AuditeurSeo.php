<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\Regle\RegleTransverse;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Orchestrateur de l'audit SEO (#345) : récupère le périmètre d'URLs
 * ({@see SourceUrls}), télécharge chaque page ({@see CollecteHtml}), lui applique les
 * règles on-page ({@see AuditeurOnPage}), puis les règles transverses au site
 * ({@see RegleTransverse}) et enfin les vérifications globales robots.txt / sitemap.xml.
 * Rend un {@see RapportAuditSeo} — l'objet-valeur figé ensuite dans un snapshot.
 */
final readonly class AuditeurSeo
{
    /**
     * @param iterable<RegleTransverse> $reglesTransverses
     */
    public function __construct(
        private SourceUrls $source,
        private CollecteHtml $collecte,
        private AuditeurOnPage $auditeurOnPage,
        #[AutowireIterator('app.regle_seo_transverse')]
        private iterable $reglesTransverses,
        #[Autowire('%app.domaine%')]
        private string $domaine,
    ) {
    }

    public function auditer(): RapportAuditSeo
    {
        $pages = [];
        foreach ($this->source->urls() as $url) {
            $pages[] = $this->collecte->recuperer($url);
        }

        $resultats = [];
        foreach ($pages as $page) {
            $resultats[] = new ResultatPage($page->url, $page->codeHttp, $this->auditeurOnPage->auditer($page));
        }

        $globaux = [];
        foreach ($this->reglesTransverses as $regle) {
            foreach ($regle->evaluer($pages) as $constat) {
                $globaux[] = $constat;
            }
        }
        foreach ($this->verifierRessourcesGlobales() as $constat) {
            $globaux[] = $constat;
        }

        return new RapportAuditSeo($resultats, $globaux);
    }

    /**
     * @return list<Constat>
     */
    private function verifierRessourcesGlobales(): array
    {
        return [
            $this->verifierRobotsTxt(),
            $this->verifierSitemapXml(),
        ];
    }

    private function verifierRobotsTxt(): Constat
    {
        $robots = $this->collecte->recuperer('https://'.$this->domaine.'/robots.txt');
        if (200 !== $robots->codeHttp) {
            return Constat::erreur('robots-txt', \sprintf('robots.txt inaccessible (code HTTP %d).', $robots->codeHttp));
        }

        return Constat::ok('robots-txt', 'robots.txt présent.');
    }

    private function verifierSitemapXml(): Constat
    {
        $sitemap = $this->collecte->recuperer('https://'.$this->domaine.'/sitemap.xml');
        if (200 !== $sitemap->codeHttp) {
            return Constat::erreur('sitemap-xml', \sprintf('sitemap.xml inaccessible (code HTTP %d).', $sitemap->codeHttp));
        }

        $precedent = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($sitemap->html);
        libxml_clear_errors();
        libxml_use_internal_errors($precedent);

        if (false === $xml) {
            return Constat::erreur('sitemap-xml', 'sitemap.xml présent mais illisible (XML invalide).');
        }

        return Constat::ok('sitemap-xml', 'sitemap.xml présent et lisible.');
    }
}
