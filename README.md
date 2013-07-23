9boxwatcher
===========

Quelques classes PHP pour contrôler une Neufbox 4 :
- activation/désactivation du wifi
- activation/désactivation du hotspot
- récupération des informations d'ADSL
- récupération des informations de réseau
- récupération des postes connectés
- reboot
- vérification de l'état de la connexion ADSL et reboot automatique si nécessaire
- et bien d'autres...

Exemples
========

```php
$neufbox = new Neufbox4('192.168.1.1');
$neufbox->login('admin', 'monmotdepasse');

// Récupération et affichage des infos de la connexion ADSL (tableau PHP)
print_r($neufbox->getAdslInfo());

// La même chose, mais "human-readable" (tableau graphique)
$formatter = new DataFormatter(DataFormatter::OUTPUT_HUMAN);
$formatter->format($neufbox->getAdslInfo());

// Activation du wifi
$neufbox->enableWifi();

// Désactivation du hotspot
$neufbox->disableHotspot();

// Récupération et affichage des postes connectés (human-readable)
$formatter->format($neufbox->getConnectedHosts());

// Récupération et affichage de l'historique d'appels (human-readable)
$formatter->format($neufbox->getPhoneCallHistory());

// Ajout d'une règle NAT/PAT (port externe : 122, port interne : 22 à l'adresse 192.168.1.10)
$neufbox->addNatRule('SSH', 'tcp', 122, '192.168.1.10', 22);

// Récupération et affichage de l'ensemble des règles NAT (human-readable)
$formatter->format($neufbox->getNatConfig());

// Export de la configuration de la box
$neufbox->exportUserConfig('nb4_userconfig.conf');

// Reboot immédiat
$neufbox->reboot();

```
