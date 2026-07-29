# ElasticPress French Addon

> **Note for English speakers:** Source code, UI strings, and comments are written in English. Translations ship as gettext PO/MO files under `languages/`. The rest of this README stays in French because the project targets French-language WordPress sites and documents French-specific Elasticsearch analyzer behavior.

Addon open-source pour [ElasticPress](https://github.com/10up/ElasticPress) qui corrige et
optimise l'analyzer Elasticsearch pour le contenu en langue française.

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

- Site : https://elasticpress-french-addon.ddev.site
- Admin : `admin` / `admin`
- Elasticsearch (depuis le conteneur web) : `http://elasticsearch:9200`
- Elasticsearch (depuis l’hôte) : `https://elasticpress-french-addon.ddev.site:9201`

Scripts Composer utiles :

| Commande | Description |
|---|---|
| `ddev composer setup` | Boot complet (WP + plugins + sync EP) |
| `ddev composer setup:wp` | Télécharge / installe WordPress si besoin |
| `ddev composer setup:plugins` | Symlink + activation des plugins |
| `ddev composer setup:ep` | Configure le host ES et relance la sync |
| `ddev composer cs` | Vérifie les WordPress Coding Standards (PHPCS) |
| `ddev composer cbf` | Corrige automatiquement ce que PHPCBF peut fixer |
| `ddev composer fetch:bulk` | Télécharge ~980 extraits Wikipédia FR (CC BY-SA) |
| `ddev composer seed:corpus` | Crée ~1000 posts de test (pièges + bulk) |
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

Le plugin s'accroche à trois filtres natifs d'ElasticPress, sans surcharger ni dupliquer
son coeur :

| Filtre ElasticPress | Usage dans ce plugin |
|---|---|
| `ep_config_mapping` | Injecte `asciifolding`, `elision`, un stemmer configurable et des stopwords additionnels dans les analyzers `default` et `default_search` |
| `ep_analyzer_language` | Force la langue Elasticsearch à `french` (stopwords `_french_`, snowball `French`) tant que l'addon est activé, indépendamment du réglage ElasticPress Language |
| `ep_post_fuzziness_arg` | Permet de fixer la fuzziness des requêtes (auto / 0 / 1 / 2) |

Un filtre `epfr_mapping` est disponible pour ajuster le mapping final depuis un projet
spécifique (ex : `stem_exclusion` sur un nom de marque qui collisionne avec un mot du
dictionnaire courant).

```php
add_filter( 'epfr_mapping', function ( array $mapping, array $settings ) {
    // Example: exclude a brand name from stemming.
    $mapping['settings']['analysis']['filter']['epfr_keywords'] = [
        'type'     => 'keyword_marker',
        'keywords' => [ 'MyBrand' ],
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

## Licence

GPL v2 or later. Voir [LICENSE](./LICENSE).

## Contribution

Développé et maintenu par [Be API](https://beapi.fr). Issues et pull requests bienvenues.
