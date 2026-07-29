# ElasticPress French Addon

> **Note for English speakers:** Source code, UI strings, and comments are written in English. Translations ship as gettext PO/MO files under `languages/`. The rest of this README stays in French because the project targets French-language WordPress sites and documents French-specific Elasticsearch analyzer behavior.

Addon open-source pour [ElasticPress](https://github.com/10up/ElasticPress) qui corrige et
optimise l'analyzer Elasticsearch pour le contenu en langue française.

Inspiré de la [documentation officielle des language analyzers](https://www.elastic.co/docs/reference/text-analysis/analysis-lang-analyzer)
(chaîne `french` : élision, stop, `keyword_marker`, stemmer `light_french`) et de l’article
[Construire un bon analyzer français pour Elasticsearch](https://jolicode.com/blog/construire-un-bon-analyzer-francais-pour-elasticsearch)
(JoliCode). On part de cette base officielle, puis on **ajoute** volontairement
l’`asciifolding` — absent de l’analyzer `french` natif — pour gérer accents et ligatures
(`haïti`/`haiti`, `bœuf`/`boeuf`).

**Prérequis :** WordPress 6.2+, PHP 8.0+, et le plugin [ElasticPress](https://wordpress.org/plugins/elasticpress/).

## Le problème

Le mapping ElasticPress par défaut n'est pas pensé pour le français :

- l'`asciifolding` n'est présent que dans un `normalizer` de type `keyword`, jamais dans la
  chaîne d'analyse `text` réellement utilisée par la recherche full-text ;
- le stemmer par défaut est un `snowball` French, réputé agressif (troncature fréquente à
  4-5 lettres), qui crée des collisions entre mots sans rapport ;
- aucune gestion de l'élision française.

Symptôme typique observé en production : une recherche sans accent (`?s=haiti`) remonte des
résultats hors sujet ("haine", "haute", "fait"), alors que la même recherche avec accent
(`?s=haïti`) fonctionne correctement. Diagnostic complet reproductible via l'API `_analyze`
d'Elasticsearch.

## Installation

```bash
cd wp-content/plugins
git clone https://github.com/beapi/elasticpress-french-addon.git
wp plugin activate elasticpress-french-addon
wp elasticpress index --setup --network-wide
```

## Développement local (DDEV)

Prérequis : [DDEV](https://ddev.com/) et Docker.

```bash
ddev start
ddev composer install
ddev composer setup
```

Cela installe WordPress dans `wordpress/`, télécharge ElasticPress via Composer,
symlinke cet addon, configure `EP_HOST` vers le service Elasticsearch DDEV, puis
lance une sync initiale.

Le setup active aussi `Query Monitor` et `ElasticPress Debugging Add-On` par
défaut, pour inspecter les requêtes ElasticPress directement dans la barre
d'admin WordPress.

- Site : https://elasticpress-french-addon.ddev.site
- Admin : `admin` / `admin`
- Elasticsearch (depuis le conteneur web) : `http://elasticsearch:9200`
- Elasticsearch (depuis l’hôte) : `https://elasticpress-french-addon.ddev.site:9201`

Scripts Composer utiles :

| Commande | Description |
|---|---|
| `ddev composer setup` | Boot complet (WP + plugins de debug + sync EP) |
| `ddev composer setup:wp` | Télécharge / installe WordPress si besoin |
| `ddev composer setup:plugins` | Symlink + activation des plugins, Query Monitor et EP Debugging |
| `ddev composer setup:ep` | Configure le host ES et relance la sync |
| `ddev composer cs` | Vérifie les WordPress Coding Standards (PHPCS) |
| `ddev composer cbf` | Corrige automatiquement ce que PHPCBF peut fixer |
| `ddev composer fetch:bulk` | Télécharge ~980 extraits Wikipédia FR (CC BY-SA) |
| `ddev composer seed:corpus` | Crée ~1000 posts de test (pièges + bulk) |
| `ddev composer verify:options` | Vérifie fonctionnellement chaque réglage de l’admin (mapping, analyse, requêtes) |
| `ddev composer verify:corpus` | Exécute les requêtes pièges (profil addon) |
| `ddev composer compare:corpus` | Compare baseline (addon off) vs addon (2× sync) |

Les hooks Git de qualité (GrumPHP) s’installent avec Composer. Sur une PR, le workflow
`.github/workflows/quality.yml` lance `composer validate` et `composer cs`.
Un tag Git déclenche le déploiement WordPress.org (`.github/workflows/release-version.yml`) ;
le fichier `.distignore` exclut les artefacts de développement du ZIP.

Après un changement de réglages du mapping, réindexer :

```bash
ddev wp elasticpress sync --setup --yes --path=wordpress
```

## Jeu de données de test (~1000 contenus)

Corpus hybride pour valider la recherche française :

- ~20 posts **pièges** (`tests/fixtures/french-search-traps.json`) : accents, collisions de stemmer, élision, fuzziness — seuls à être assertés ;
- ~980 extraits **Wikipédia FR** (`tests/fixtures/french-search-bulk.json.gz`, CC BY-SA) : bruit de ranking réaliste.

Voir aussi [`tests/fixtures/ATTRIBUTION.md`](tests/fixtures/ATTRIBUTION.md).

```bash
ddev composer fetch:bulk          # une fois (~980 extraits), ou pour régénérer
ddev composer seed:corpus         # ~1000 posts, sans sync EP inline
ddev wp elasticpress sync --setup --yes --path=wordpress
ddev composer verify:options
ddev composer verify:corpus
ddev composer compare:corpus      # purge + reseed + 2× sync --setup : prévoir plusieurs minutes
```

Les assertions `verify` / `compare` sont **scopées aux posts pièges** (présence / absence de match via ElasticPress), pas au top 20 du corpus bulk — sinon les articles Wikipédia évinceraient les pièges sur des termes fréquents (`amour`, `cheval`, etc.).

Options utiles :

```bash
# Reprendre un fetch interrompu
php bin/fetch-bulk-corpus.php --count=980 --resume

# Resemer en partant de zéro (évite les doublons si le bulk a été régénéré)
ddev wp eval-file bin/seed-search-corpus.php epfr-purge --path=wordpress

# Vérifier le profil baseline (addon désactivé + réindex)
ddev wp eval-file bin/toggle-addon.php epfr-enabled-0 --path=wordpress
ddev wp elasticpress sync --setup --yes --path=wordpress
ddev wp eval-file bin/verify-search-corpus.php epfr-profile-baseline --path=wordpress
```

## Fonctionnement

Le plugin s'accroche aux filtres natifs d'ElasticPress, sans surcharger ni dupliquer
son coeur :

| Filtre ElasticPress | Usage dans ce plugin |
|---|---|
| `ep_config_mapping` | Injecte `asciifolding`, `elision`, stemmer, stopwords additionnels, `stem_exclusion` ; en mode dual, `default`/`default_search` restent light et `epfr_heavy` sert aux multi-fields |
| `ep_post_mapping` | En mode dual, ajoute les multi-fields `.stemmed` sur `post_title`, `post_content`, `post_excerpt` |
| `ep_formatted_args` (prio 25) | En mode dual, injecte les champs `.stemmed` (boost réduit) dans les `multi_match` **après** le weighting ElasticPress (prio 20), qui sinon les supprimerait |
| `ep_analyzer_language` | Force la langue Elasticsearch à `french` (stopwords `_french_`, snowball `French`) tant que l'addon est activé, indépendamment du réglage ElasticPress Language |
| `ep_post_fuzziness_arg` | Permet de fixer la fuzziness des requêtes (auto / 0 / 1 / 2) |

Un filtre `epfr_mapping` reste disponible pour les ajustements avancés (ex. `stemmer_override`).
Pour une simple exclusion de stemming, préférez le réglage `stem_exclusion` de l’admin.

```php
add_filter( 'epfr_mapping', function ( array $mapping, array $settings ) {
    // Example: custom stemmer overrides (advanced).
    $mapping['settings']['analysis']['filter']['epfr_stemmer_override'] = [
        'type'  => 'stemmer_override',
        'rules' => [ 'croissant=>croisan' ],
    ];
    return $mapping;
}, 10, 2 );
```

## Internationalisation

Le code source et les chaînes UI sont en anglais (text domain `elasticpress-french-addon`).
Les traductions se trouvent dans `languages/` :

- `elasticpress-french-addon.pot` — catalogue des chaînes
- `elasticpress-french-addon-fr_FR.po` / `.mo` — traduction française

Sur un site WordPress en `fr_FR`, l’interface d’administration s’affiche automatiquement en français.

## Réglages disponibles

Réglable dans **ElasticPress > French Addon**, ou directement en base via l'option
`epfr_settings` :

- `asciifolding` (bool) : ignore les accents à l'indexation et à la recherche.
- `elision` (bool) : gère l'élision française (l', d', qu'...).
- `stemmer` (`none` | `minimal_french` | `light_french` | `french`) : niveau de racinisation.
- `fuzziness` (`auto` | `0` | `1` | `2`) : tolérance aux fautes de frappe.
- `extra_stopwords` (string, séparé par virgules) : mots additionnels à ignorer.
- `stem_exclusion` (string, séparé par virgules) : mots exclus du stemming (`keyword_marker`), ex. `croix` pour éviter la collision « La Croix » / « croissant ».
- `dual_analyzers` (bool, défaut `false`) : analyzer light sur les champs principaux (pertinence) + heavy stémmé sur `.stemmed` (rappel). Opt-in ; réindex `--setup` obligatoire.

## Important

- Toute modification de réglage nécessite une réindexation complète
  (`wp elasticpress index --setup --network-wide`) : un mapping Elasticsearch ne se met
  jamais à jour à chaud sur un index existant.
- Ce plugin ne gère pas les synonymes : utilisez la fonctionnalité *Synonyms* native
  d'ElasticPress, prévue à cet effet.
- Testez toujours vos changements de stemmer sur un jeu de requêtes de référence avant mise
  en production : un stemmer plus doux change le classement de **toutes** les recherches du
  site, pas seulement le cas qui a motivé le changement.

## Vérifier le résultat

```bash
curl -s 'http://YOUR_CLUSTER:9200/YOUR_INDEX/_analyze' \
  -H 'Content-Type: application/json' \
  -d '{"analyzer":"default","text":"Haïti haïti haiti haine haute fait"}'
```

Les trois premières formes doivent produire le même token ; "haine", "haute" et "fait" ne
doivent plus être ramenés à une racine proche de "haiti".

Avec `stem_exclusion=croix`, comparer :

```bash
curl -s 'http://YOUR_CLUSTER:9200/YOUR_INDEX/_analyze' \
  -H 'Content-Type: application/json' \
  -d '{"analyzer":"default","text":"La Croix croissant"}'
```

En mode dual, comparer l’analyzer light (`default`) et le champ stémmé :

```bash
curl -s 'http://YOUR_CLUSTER:9200/YOUR_INDEX/_analyze' \
  -H 'Content-Type: application/json' \
  -d '{"field":"post_content.stemmed","text":"tomates"}'
```

## Références

Liste classée par utilité pour comprendre et étendre cet addon :

1. **[Language analyzers (Elastic)](https://www.elastic.co/docs/reference/text-analysis/analysis-lang-analyzer)** — source de vérité : définition reproductible de l’analyzer `french` natif. À privilégier sur tout blog tiers en cas de doute.
2. **[Construire un bon analyzer français (JoliCode)](https://jolicode.com/blog/construire-un-bon-analyzer-francais-pour-elasticsearch)** — référence francophone : limites du french natif, dual light/heavy, pertinence vs rappel.
3. **[Leviers Elasticsearch pour les spécificités linguistiques (blog Elastic FR)](https://www.elastic.co/fr/blog/leviers-elasticsearch-pour-le-traitement-des-specificites-linguistiques)** — cas concret `stem_exclusion` (« La Croix » / « croissant ») et `_analyze`.
4. **[Analyzer reference](https://www.elastic.co/docs/reference/text-analysis/analyzer-reference)** — vue d’ensemble des analyzers (french, standard, keyword…).
5. **[ASCII Folding et `_analyze` (Aymeric Lagier)](https://aymericlagier.com/2016/05/04/ascii-folding-dans-elasticsearch-et-appel-de-_analyze/)** — diagnostic via l’API `_analyze`.
6. **[Elasticsearch: The Definitive Guide — Languages (O'Reilly)](https://www.oreilly.com/library/view/elasticsearch-the-definitive/9781449358532/part03ch01.html)** — pédagogique mais **historique** (ES 1.x/2.x) : l’affirmation selon laquelle le french retire les diacritiques ne correspond plus à l’implémentation actuelle (pas d’asciifolding dans le french stock).
7. **[Discuss — Language analyzer en français](https://discuss.elastic.co/t/language-analyzer-en-francais/41045)** / **[Google Groups elasticsearch-fr](https://groups.google.com/g/elasticsearch-fr/c/MLYdicQ0xGo)** — compléments communautaires (pièges de config, démarche `_analyze`).

## Licence

GPL v2 or later. Voir [LICENSE](./LICENSE).

## Contribution

Développé et maintenu par [Be API](https://beapi.fr). Issues et pull requests bienvenues.
