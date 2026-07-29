# Algorithme de recherche française

Ce document décrit la chaîne d’analyse et le comportement requête mis en place par
**ElasticPress French Addon**. L’implémentation de référence est
[`includes/class-epfr-analyzer.php`](../includes/class-epfr-analyzer.php).

## Objectifs

Corriger trois défauts du mapping ElasticPress par défaut pour le français :

1. **Asciifolding absent** de la chaîne `text` (présent seulement dans un normalizer
   `keyword`) → `haiti` et `haïti` ne produisent pas le même token.
2. **Stemmer Snowball trop agressif** → collisions entre mots sans rapport
   (`haine` / `haute` / `fait` vs `haïti`).
3. **Pas d’élision** → `l'article`, `d'un`, `qu'il` mal tokenisés.

On s’aligne sur l’analyzer `french` officiel Elasticsearch (élision → lowercase → stop →
`keyword_marker` → stemmer `light_french`), puis on **ajoute** volontairement
`asciifolding` (absent du `french` natif) pour accents et ligatures.

## Vue d’ensemble

```mermaid
flowchart LR
  text[Texte indexé / requête] --> tok[tokenizer standard]
  tok --> elision[epfr_elision]
  elision --> lower[lowercase]
  lower --> ascii[asciifolding]
  ascii --> stop[ep_stop + epfr_extra_stop]
  stop --> syn[filtres tiers EP / synonymes]
  syn --> kw[epfr_keywords]
  kw --> stem[epfr_stemmer]
  stem --> tokens[Tokens]
```

Ordre réel reconstruit par `build_analyzer()` (mode **full**, réglages par défaut) :

| Étape | Filtre | Rôle |
|---|---|---|
| 1 | `epfr_elision` | Retire les articles élidés (`l'`, `d'`, `qu'`, …) |
| 2 | `lowercase` | Normalise la casse |
| 3 | `asciifolding` | Replie accents / ligatures (`ï`→`i`, `œ`→`oe`) |
| 4 | `ep_stop` (+ `epfr_extra_stop`) | Stopwords `_french_` puis liste admin optionnelle |
| 5 | *(filtres ElasticPress déjà présents)* | Synonymes, shingles, etc. — **jamais retirés** |
| 6 | `epfr_keywords` | Marque les mots exclus du stemming (`stem_exclusion`) |
| 7 | `epfr_stemmer` | Racinisation (`light_french` par défaut) |

Le stemmer Snowball / native déjà présent dans la chaîne EP est **retiré**, puis remplacé
par `epfr_stemmer` si un niveau de stemming est choisi.

## Détail des leviers

### Élision (`elision`)

Filtre ES `elision` avec `articles_case: true` et la liste :

`l`, `m`, `t`, `qu`, `n`, `s`, `j`, `d`, `c`, `jusqu`, `quoiqu`, `lorsqu`, `puisqu`

Placé **en tête** de chaîne (comme l’analyzer `french` officiel), avant `lowercase`.

### Asciifolding (`asciifolding`)

Inséré **juste après** `lowercase`. Si l’option est désactivée, les filtres
`asciifolding` / `ep_asciifolding` éventuellement présents sont retirés.

Effet attendu : `Haïti`, `haïti`, `haiti` → même token (modulo stemming).

### Stopwords

- Base : filtre ElasticPress `ep_stop` forcé en `_french_` via `ep_analyzer_language`
  (contexte `filter_ep_stop`).
- Optionnel : `epfr_extra_stop` (liste CSV admin, `ignore_case: true`), ajouté **après**
  les stopwords existants.

### Exclusion de stemming (`stem_exclusion`)

Filtre `keyword_marker` (`epfr_keywords`) **immédiatement avant** le stemmer.
Les mots listés ne sont pas racinisés — utile pour éviter `croix` → collision avec
`croissant`. Non enregistré si la liste est vide ou si `stemmer=none` (piège ES classique).

### Stemmer (`stemmer`)

| Valeur | Comportement |
|---|---|
| `none` | Pas de stemming (ni `epfr_stemmer`, ni `epfr_keywords`) |
| `minimal_french` | Très doux |
| `light_french` | **Défaut** — bon compromis pertinence / rappel |
| `french` | Plus agressif |

### Fuzziness (temps de requête)

Filtres `ep_post_fuzziness_arg` et `ep_post_match_fuzziness` : force `auto`, `0`, `1`
ou `2` selon le réglage. Indépendant du mapping ; **pas** besoin de `--setup` pour
changer uniquement la fuzziness, mais tester sur un jeu de requêtes reste recommandé.

### Langue forcée

Tant que l’addon est activé, `ep_analyzer_language` renvoie :

- `_french_` pour les stopwords (`filter_ep_stop`) ;
- `French` pour le snowball EP (`filter_ewp_snowball`) ;
- `french` sinon.

Cela évite qu’un réglage « Language » ElasticPress non français casse la chaîne.

## Mode dual (opt-in)

Réglage `dual_analyzers` (défaut `false`). Actif seulement si l’addon est enabled **et**
`stemmer !== none`.

| Surface | Analyzer | But |
|---|---|---|
| Champs principaux (`post_title`, `post_content`, `post_excerpt`) | chaîne **light** (sans keywords / stemmer) | Précision / ranking |
| Multi-fields `.stemmed` | analyzer nommé `epfr_heavy` (chaîne **full**) | Rappel (formes fléchies) |

À l’indexation (`ep_post_mapping`) : ajout de
`post_*.fields.stemmed` avec `"analyzer": "epfr_heavy"`.

À la requête (`ep_formatted_args`, priorité **25**, après le weighting EP en prio 20) :

- parcours récursif des clauses ;
- pour chaque `multi_match` **sauf** `cross_fields` (analyzers hétérogènes incompatibles),
  ajout de `post_title.stemmed`, `post_content.stemmed`, `post_excerpt.stemmed` ;
- boost = boost parent × **0.5** (`STEMMED_BOOST_FACTOR`).

Sans cette injection post-weighting, ElasticPress reconstruirait la liste des champs et
supprimerait les `.stemmed` inconnus.

```mermaid
flowchart TB
  subgraph index [Indexation]
    A[Texte] --> L[Champ principal — analyzer light]
    A --> H[.stemmed — epfr_heavy]
  end
  subgraph query [Requête multi_match]
    Q[Terme] --> ML[Match light — boost plein]
    Q --> MH[Match .stemmed — boost × 0.5]
  end
```

## Intégration ElasticPress

| Filtre | Rôle |
|---|---|
| `ep_config_mapping` | Construit filtres + analyzers `default` / `default_search` (+ `epfr_heavy`) |
| `ep_post_mapping` | Multi-fields `.stemmed` en mode dual |
| `ep_formatted_args` (25) | Injecte `.stemmed` dans les `multi_match` |
| `ep_analyzer_language` | Force le français selon le contexte EP |
| `ep_post_fuzziness_arg` / `ep_post_match_fuzziness` | Fuzziness admin |
| `epfr_mapping` | Hook projet pour ajustements avancés (`stemmer_override`, etc.) |

Les filtres déjà présents (synonymes EP, shingles DidYouMean, …) sont **conservés** :
l’addon ajoute ou remplace uniquement sa propre chaîne stemmer / folding / élision.

## Réglages par défaut

Voir `Settings::get_defaults()` :

| Clé | Défaut |
|---|---|
| `enabled` | `true` |
| `asciifolding` | `true` |
| `elision` | `true` |
| `stemmer` | `light_french` |
| `fuzziness` | `auto` |
| `extra_stopwords` | `''` |
| `stem_exclusion` | `''` |
| `dual_analyzers` | `false` |

## Contrainte opérationnelle

Toute modification de mapping / analyzer exige une **réindexation complète**
(`wp elasticpress index --setup`). Un sync sans `--setup` ne recrée pas l’index et laisse
l’ancien mapping en place.

## Vérification rapide

```bash
curl -s 'http://YOUR_CLUSTER:9200/YOUR_INDEX/_analyze' \
  -H 'Content-Type: application/json' \
  -d '{"analyzer":"default","text":"Haïti haïti haiti haine haute fait"}'
```

Attendu avec les défauts : les trois formes « Haïti » partagent le même token ; `haine`,
`haute`, `fait` ne doivent plus collapser vers une racine proche de `haiti`.

Scripts locaux utiles : `ddev composer verify:options`, `verify:corpus`, `compare:corpus`.

## Références

1. [Language analyzers (Elastic)](https://www.elastic.co/docs/reference/text-analysis/analysis-lang-analyzer)
2. [Construire un bon analyzer français (JoliCode)](https://jolicode.com/blog/construire-un-bon-analyzer-francais-pour-elasticsearch)
3. [Leviers Elasticsearch — spécificités linguistiques](https://www.elastic.co/fr/blog/leviers-elasticsearch-pour-le-traitement-des-specificites-linguistiques)
