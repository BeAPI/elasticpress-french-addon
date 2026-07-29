=== ElasticPress French Addon ===
Contributors: beapi
Tags: elasticpress, elasticsearch, search, french, i18n
Requires at least: 6.2
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires Plugins: elasticpress

Corrige et optimise l'analyzer ElasticPress pour le contenu en langue française.

== Description ==

Le mapping par défaut d'ElasticPress n'est pas adapté au français : pas d'asciifolding
opérationnel sur les champs de recherche full-text, stemmer Snowball souvent trop agressif,
absence de gestion de l'élision. Résultat typique : une recherche sans accent ("haiti")
remonte des résultats sans rapport ("haine", "haute", "fait"), alors que la même recherche
avec accent ("haïti") fonctionne correctement.

Ce plugin s'active comme un addon à ElasticPress et corrige ces trois points via le filtre
natif `ep_config_mapping`, sans jamais toucher au coeur d'ElasticPress ni aux filtres déjà
définis par d'autres plugins (synonymes, shingle, etc.).

**Ce que fait le plugin :**

* Ajoute un filtre `asciifolding` réellement actif sur les analyzers `default` et
  `default_search` (le normalizer `keyword` livré par défaut avec ElasticPress ne
  s'applique jamais à la recherche full-text, c'est un piège classique).
* Ajoute la gestion de l'élision française (l'article, d'un, qu'il...).
* Permet de choisir le niveau de racinisation (aucun, minimal, léger, complet) au lieu
  du Snowball français imposé par défaut, souvent responsable de collisions non pertinentes.
* Permet d'ajuster la fuzziness des requêtes (auto / stricte / 1 faute / 2 fautes).
* Permet d'ajouter des stopwords additionnels sans toucher à la liste standard.

**Ce que le plugin ne fait pas :**

* Il ne gère pas les synonymes : utilisez la fonctionnalité Synonyms native d'ElasticPress.
* Il ne déclenche aucune réindexation automatique : une modification d'analyzer ne
  s'applique qu'aux index créés après son activation.

== Installation ==

1. Installez et activez ElasticPress au préalable.
2. Installez ce plugin (dossier `elasticpress-french-addon` dans `wp-content/plugins`).
3. Activez le plugin.
4. Ajustez les réglages dans ElasticPress > French Addon si besoin (les valeurs par
   défaut conviennent à la majorité des sites français).
5. Réindexez obligatoirement :
   `wp elasticpress index --setup --network-wide`
6. Sur un multisite, recréez l'alias réseau si nécessaire :
   `wp elasticpress recreate-network-alias`

== Vérifier le résultat ==

`curl -s 'http://VOTRE_CLUSTER:9200/VOTRE_INDEX/_analyze' -H 'Content-Type: application/json' -d '{"analyzer":"default","text":"Haïti haïti haiti"}'`

Les trois formes doivent produire le même token après réindexation.

== Frequently Asked Questions ==

= Pourquoi mes recherches accentuées fonctionnent mais pas les non-accentuées (ou l'inverse) ? =

C'est exactement le symptôme que corrige ce plugin. Sans asciifolding actif sur la chaîne
de recherche, "haiti" et "haïti" produisent deux tokens différents à l'indexation, donc
seule la forme correspondant au contenu réel matche en clause exacte.

= Le plugin modifie-t-il mes données existantes ? =

Non. Il modifie uniquement le mapping utilisé lors de la création d'un nouvel index.
Une réindexation complète est nécessaire pour que le contenu existant soit retokenisé.

= Compatible multisite ? =

Oui, le filtre s'applique à `ep_config_mapping` qui est appelé par index, donc pour
chaque site du réseau.

== Changelog ==

= 1.0.0 =
* Version initiale : asciifolding, élision, stemmer configurable, fuzziness, stopwords additionnels.
