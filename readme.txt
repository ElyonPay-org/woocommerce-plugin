=== ElyonPay for WooCommerce ===
Contributors: elyonpay
Tags: mobile money, paiement, woocommerce, mtn momo, orange money
Requires at least: 5.6
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.2.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Acceptez le Mobile Money (MTN, Orange, Wave, Moov, Airtel) et les cartes bancaires sur votre boutique WooCommerce, partout en Afrique.

== Description ==

**ElyonPay for WooCommerce** connecte votre boutique à la passerelle de paiement
**ElyonPay** et vous permet d'encaisser en quelques clics au Cameroun, en Afrique
de l'Ouest et Centrale, et au-delà — via **Mobile Money** comme par **carte
bancaire**.

Vos clients paient sur une page sécurisée hébergée par ElyonPay (USSD pour le
Mobile Money, 3D Secure pour les cartes), puis reviennent sur votre boutique.
Le statut de chaque commande n'est validé qu'après une **vérification côté
serveur** : aucun paiement n'est marqué comme réglé sur la seule foi d'une
redirection.

= Moyens de paiement pris en charge =

* **Mobile Money** : MTN MoMo, Orange Money, Wave, Moov Money, Airtel Money
* **Cartes bancaires** : Visa et Mastercard (3D Secure)

= Pourquoi choisir ElyonPay =

* **Pensé pour l'Afrique** — terminologie, opérateurs et devises locales
  (XAF, XOF, EUR, USD, GBP, NGN, KES, CDF).
* **Checkout classique et par blocs** — compatible avec l'éditeur de blocs
  WooCommerce comme avec le tunnel de commande historique.
* **Paiement fiabilisé** — vérification du statut côté serveur après chaque
  transaction, et re-contrôle automatique des paiements en attente.
* **Sans données sensibles chez vous** — les coordonnées de carte sont saisies
  sur la page sécurisée d'ElyonPay, jamais sur votre boutique.
* **Compatible HPOS** — prêt pour le stockage haute performance des commandes
  de WooCommerce.
* **Mode test intégré** — un environnement sandbox complet pour tout valider
  avant de passer en production.

= Comment ça marche =

1. Le client finalise son panier et choisit ElyonPay.
2. Il est redirigé vers la page de paiement ElyonPay (Mobile Money ou carte).
3. Une fois le paiement effectué, il revient sur votre boutique.
4. Le plugin interroge l'API ElyonPay et met à jour la commande selon le
   statut réel : payée, échouée, ou en attente.

= Compte requis =

Ce plugin nécessite un compte marchand ElyonPay. La création de compte, la
validation (KYB) et l'obtention des identifiants se font sur
[elyonpay.com](https://elyonpay.com). La documentation technique de l'API est
disponible sur [docs.elyonpay.com](https://docs.elyonpay.com).

== Installation ==

= Installation simple =

1. Téléchargez l'archive `elyonpay-woocommerce.zip`.
2. Dans l'administration WordPress : **Extensions → Ajouter → Téléverser une
   extension**, puis sélectionnez l'archive et cliquez sur **Installer
   maintenant**.
3. Activez l'extension.
4. Rendez-vous dans **WooCommerce → Réglages → Paiements**, activez
   **ElyonPay** puis cliquez sur **Gérer**.
5. Choisissez l'environnement (test ou production), saisissez vos identifiants
   marchand ElyonPay, puis enregistrez.

= Configuration =

* **Mode test** : utilise l'environnement sandbox d'ElyonPay (aucune
  transaction réelle). Décochez-le pour encaisser en production.
* **Identifiants** : votre e-mail et votre mot de passe marchand ElyonPay
  (champs distincts pour le test et la production).
* **Rôle** : `ROLE_MERCHANT_ADMIN` en production. Le compte de test documenté
  par ElyonPay utilise `ROLE_CUSTOMER` ; si l'authentification sandbox échoue,
  basculez le rôle sur `ROLE_CUSTOMER`.

= Prérequis =

* WordPress 5.6 ou supérieur
* WooCommerce 4.0 ou supérieur
* PHP 7.4 ou supérieur avec l'extension cURL
* Un compte marchand ElyonPay

== Frequently Asked Questions ==

= Le plugin est-il gratuit ? =

Oui, l'extension est gratuite et open source (licence MIT). Les frais éventuels
liés aux transactions relèvent de votre contrat marchand ElyonPay.

= Dois-je avoir un compte ElyonPay ? =

Oui. Vous devez disposer d'un compte marchand ElyonPay validé pour obtenir vos
identifiants. Rendez-vous sur elyonpay.com pour créer le vôtre.

= Quels pays et quelles devises sont pris en charge ? =

ElyonPay cible le Cameroun, l'Afrique de l'Ouest et Centrale, et prend en charge
les devises XAF, XOF, EUR, USD, GBP, NGN, KES et CDF. La disponibilité des
opérateurs Mobile Money dépend du pays.

= Le plugin fonctionne-t-il avec le paiement par blocs (Checkout Blocks) ? =

Oui. ElyonPay s'affiche aussi bien dans le tunnel de commande classique que dans
le nouveau paiement par blocs de WooCommerce.

= Un numéro de téléphone est-il nécessaire ? =

Oui pour le Mobile Money. Le plugin met automatiquement le numéro au format
international à partir du pays de facturation de la commande.

= Comment les paiements sont-ils sécurisés ? =

Les coordonnées de paiement sont saisies sur la page sécurisée d'ElyonPay, pas
sur votre boutique. Après la redirection, le plugin confirme le paiement en
interrogeant directement l'API ElyonPay : une commande n'est validée que si le
paiement est réellement confirmé côté serveur.

= Que se passe-t-il si le client ferme la page avant la fin ? =

La commande reste « en attente ». Le plugin replanifie automatiquement une
vérification du statut, et vous pouvez aussi le contrôler depuis la fiche de
commande.

= Mes identifiants sont-ils en sécurité ? =

Ils sont enregistrés dans les réglages de la passerelle et utilisés uniquement
côté serveur pour obtenir un jeton d'accès (JWT), mis en cache temporairement.
Ils ne sont jamais exposés dans le navigateur.

== Screenshots ==

1. La page de réglages ElyonPay dans WooCommerce (environnement, identifiants, rôle).
2. ElyonPay proposé comme moyen de paiement dans le tunnel de commande.
3. Le moyen de paiement ElyonPay dans le paiement par blocs, avec le bandeau « mode test ».
4. La page de paiement sécurisée ElyonPay (Mobile Money et carte).
5. Le tableau récapitulatif des statuts de transaction côté administration.

== Changelog ==

= 1.2.0 =
* Reconstruction sur le SDK PHP officiel ElyonPay (logique de statuts mutualisée).
* Correction de la mise au format international du numéro de téléphone (indicatif pays).
* La réduction de stock et le vidage du panier n'interviennent plus qu'après confirmation du paiement.
* Vérification de la clé de commande au retour, compatibilité HPOS, compatibilité PHP 7.4.
* Paiement par blocs : décodage des entités HTML (titres accentués), version d'asset alignée.

= 1.1.1 =
* Version autonome précédente (appels API directs).

== Upgrade Notice ==

= 1.2.0 =
Mise à jour recommandée : corrige la validation des paiements (statuts) et la mise au format des numéros de téléphone. Pensez à exécuter « composer install » lors de la construction de l'archive.