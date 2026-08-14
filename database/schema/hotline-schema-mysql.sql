
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `incident_id` bigint(20) unsigned DEFAULT NULL,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `actor_role` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `activity_logs_incident_id_foreign` (`incident_id`),
  KEY `activity_logs_actor_id_created_at_index` (`actor_id`,`created_at`),
  CONSTRAINT `activity_logs_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`),
  CONSTRAINT `activity_logs_incident_id_foreign` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `call_attempt_operator_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `call_attempt_operator_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `call_attempt_id` bigint(20) unsigned NOT NULL,
  `operator_id` bigint(20) unsigned NOT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `outcome` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `started_at` timestamp NOT NULL,
  `answered_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `call_attempt_operator_attempts_operator_id_foreign` (`operator_id`),
  KEY `call_attempt_operator_attempts_call_attempt_id_created_at_index` (`call_attempt_id`,`created_at`),
  CONSTRAINT `call_attempt_operator_attempts_call_attempt_id_foreign` FOREIGN KEY (`call_attempt_id`) REFERENCES `call_attempts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `call_attempt_operator_attempts_operator_id_foreign` FOREIGN KEY (`operator_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `call_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `call_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `citizen_id` bigint(20) unsigned DEFAULT NULL,
  `incident_id` bigint(20) unsigned DEFAULT NULL,
  `answered_by_operator_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `outcome` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `caller_latitude` decimal(10,7) DEFAULT NULL,
  `caller_longitude` decimal(10,7) DEFAULT NULL,
  `started_at` timestamp NOT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `call_attempts_incident_id_foreign` (`incident_id`),
  KEY `call_attempts_answered_by_operator_id_foreign` (`answered_by_operator_id`),
  KEY `call_attempts_caller_id_created_at_index` (`created_at`),
  KEY `call_attempts_citizen_id_foreign` (`citizen_id`),
  CONSTRAINT `call_attempts_answered_by_operator_id_foreign` FOREIGN KEY (`answered_by_operator_id`) REFERENCES `users` (`id`),
  CONSTRAINT `call_attempts_citizen_id_foreign` FOREIGN KEY (`citizen_id`) REFERENCES `users` (`id`),
  CONSTRAINT `call_attempts_incident_id_foreign` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `call_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `call_participants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `call_session_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `participant_role` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `joined_at` timestamp NOT NULL,
  `left_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `call_participants_user_id_foreign` (`user_id`),
  KEY `call_participants_call_session_id_user_id_joined_at_index` (`call_session_id`,`user_id`,`joined_at`),
  CONSTRAINT `call_participants_call_session_id_foreign` FOREIGN KEY (`call_session_id`) REFERENCES `call_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `call_participants_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `call_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `call_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `incident_id` bigint(20) unsigned NOT NULL,
  `citizen_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `outcome` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `started_at` timestamp(3) NOT NULL,
  `answered_at` timestamp(3) NULL DEFAULT NULL,
  `ended_at` timestamp(3) NULL DEFAULT NULL,
  `created_at` timestamp(3) NULL DEFAULT NULL,
  `updated_at` timestamp(3) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `call_sessions_incident_id_started_at_index` (`incident_id`,`started_at`),
  KEY `call_sessions_citizen_id_foreign` (`citizen_id`),
  CONSTRAINT `call_sessions_citizen_id_foreign` FOREIGN KEY (`citizen_id`) REFERENCES `users` (`id`),
  CONSTRAINT `call_sessions_incident_id_foreign` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `command_broadcasts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `command_broadcasts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `tone` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `audience` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'global',
  `target_roles_json` json DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned NOT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `realtime_status` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `realtime_meta_json` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `command_broadcasts_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `command_broadcasts_audience_published_at_index` (`audience`,`published_at`),
  KEY `command_broadcasts_tone_created_at_index` (`tone`,`created_at`),
  KEY `command_broadcasts_expires_at_index` (`expires_at`),
  CONSTRAINT `command_broadcasts_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incident_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `incident_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `sort_order` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incident_citizen_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `incident_citizen_locations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `incident_id` bigint(20) unsigned NOT NULL,
  `citizen_id` bigint(20) unsigned DEFAULT NULL,
  `operator_id` bigint(20) unsigned DEFAULT NULL,
  `call_session_id` bigint(20) unsigned DEFAULT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `accuracy` decimal(10,2) DEFAULT NULL,
  `altitude` decimal(10,2) DEFAULT NULL,
  `altitude_accuracy` decimal(10,2) DEFAULT NULL,
  `heading` decimal(6,2) DEFAULT NULL,
  `heading_source` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `captured_at` timestamp NOT NULL,
  `received_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incident_citizen_locations_citizen_id_foreign` (`citizen_id`),
  KEY `incident_citizen_locations_operator_id_foreign` (`operator_id`),
  KEY `incident_citizen_locations_incident_id_captured_at_index` (`incident_id`,`captured_at`),
  KEY `incident_citizen_locations_incident_id_received_at_index` (`incident_id`,`received_at`),
  KEY `incident_citizen_locations_call_session_id_captured_at_index` (`call_session_id`,`captured_at`),
  CONSTRAINT `incident_citizen_locations_call_session_id_foreign` FOREIGN KEY (`call_session_id`) REFERENCES `call_sessions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `incident_citizen_locations_citizen_id_foreign` FOREIGN KEY (`citizen_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `incident_citizen_locations_incident_id_foreign` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `incident_citizen_locations_operator_id_foreign` FOREIGN KEY (`operator_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incident_incident_type`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `incident_incident_type` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `incident_id` bigint(20) unsigned NOT NULL,
  `incident_type_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `incident_incident_type_incident_id_incident_type_id_unique` (`incident_id`,`incident_type_id`),
  KEY `incident_incident_type_incident_type_id_foreign` (`incident_type_id`),
  CONSTRAINT `incident_incident_type_incident_id_foreign` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`),
  CONSTRAINT `incident_incident_type_incident_type_id_foreign` FOREIGN KEY (`incident_type_id`) REFERENCES `incident_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incident_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `incident_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `incident_id` bigint(20) unsigned NOT NULL,
  `sender_id` bigint(20) unsigned NOT NULL,
  `sender_role` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci,
  `type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'message',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `incident_messages_sender_id_foreign` (`sender_id`),
  KEY `incident_messages_incident_id_created_at_index` (`incident_id`,`created_at`),
  CONSTRAINT `incident_messages_incident_id_foreign` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`),
  CONSTRAINT `incident_messages_sender_id_foreign` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incident_resources_needed`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `incident_resources_needed` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `incident_id` bigint(20) unsigned NOT NULL,
  `incident_type_id` bigint(20) unsigned DEFAULT NULL,
  `resource_type_id` bigint(20) unsigned NOT NULL,
  `quantity_required` int(10) unsigned NOT NULL DEFAULT '1',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incident_resources_needed_incident_id_foreign` (`incident_id`),
  KEY `incident_resources_needed_resource_type_id_foreign` (`resource_type_id`),
  KEY `incident_resources_needed_incident_type_id_foreign` (`incident_type_id`),
  CONSTRAINT `incident_resources_needed_incident_id_foreign` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`),
  CONSTRAINT `incident_resources_needed_incident_type_id_foreign` FOREIGN KEY (`incident_type_id`) REFERENCES `incident_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `incident_resources_needed_resource_type_id_foreign` FOREIGN KEY (`resource_type_id`) REFERENCES `resource_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incident_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `incident_transfers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `incident_id` bigint(20) unsigned NOT NULL,
  `from_operator_id` bigint(20) unsigned NOT NULL,
  `to_operator_id` bigint(20) unsigned NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `requested_at` timestamp NOT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incident_transfers_incident_id_foreign` (`incident_id`),
  KEY `incident_transfers_from_operator_id_foreign` (`from_operator_id`),
  KEY `incident_transfers_to_operator_id_foreign` (`to_operator_id`),
  CONSTRAINT `incident_transfers_from_operator_id_foreign` FOREIGN KEY (`from_operator_id`) REFERENCES `users` (`id`),
  CONSTRAINT `incident_transfers_incident_id_foreign` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`),
  CONSTRAINT `incident_transfers_to_operator_id_foreign` FOREIGN KEY (`to_operator_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incident_type_default_resources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `incident_type_default_resources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `incident_type_id` bigint(20) unsigned NOT NULL,
  `resource_type_id` bigint(20) unsigned NOT NULL,
  `quantity_required` int(10) unsigned NOT NULL DEFAULT '1',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `sort_order` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `itdr_type_resource_unique` (`incident_type_id`,`resource_type_id`),
  KEY `incident_type_default_resources_resource_type_id_foreign` (`resource_type_id`),
  CONSTRAINT `incident_type_default_resources_incident_type_id_foreign` FOREIGN KEY (`incident_type_id`) REFERENCES `incident_types` (`id`),
  CONSTRAINT `incident_type_default_resources_resource_type_id_foreign` FOREIGN KEY (`resource_type_id`) REFERENCES `resource_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incident_type_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `incident_type_details` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `incident_id` bigint(20) unsigned NOT NULL,
  `incident_type_id` bigint(20) unsigned NOT NULL,
  `field_id` bigint(20) unsigned NOT NULL,
  `field_label` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_value` text COLLATE utf8mb4_unicode_ci,
  `input_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `options_json` json DEFAULT NULL,
  `config_json` json DEFAULT NULL,
  `unit` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `placeholder` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incident_type_details_incident_id_foreign` (`incident_id`),
  KEY `incident_type_details_incident_type_id_foreign` (`incident_type_id`),
  KEY `incident_type_details_field_id_foreign` (`field_id`),
  CONSTRAINT `incident_type_details_field_id_foreign` FOREIGN KEY (`field_id`) REFERENCES `incident_type_fields` (`id`),
  CONSTRAINT `incident_type_details_incident_id_foreign` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`),
  CONSTRAINT `incident_type_details_incident_type_id_foreign` FOREIGN KEY (`incident_type_id`) REFERENCES `incident_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incident_type_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `incident_type_fields` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `incident_type_id` bigint(20) unsigned NOT NULL,
  `field_key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_label` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `input_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `options_json` json DEFAULT NULL,
  `config_json` json DEFAULT NULL,
  `default_value` text COLLATE utf8mb4_unicode_ci,
  `placeholder` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int(10) unsigned NOT NULL DEFAULT '0',
  `min` decimal(10,2) DEFAULT NULL,
  `max` decimal(10,2) DEFAULT NULL,
  `step` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `incident_type_fields_incident_type_id_field_key_unique` (`incident_type_id`,`field_key`),
  CONSTRAINT `incident_type_fields_incident_type_id_foreign` FOREIGN KEY (`incident_type_id`) REFERENCES `incident_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incident_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `incident_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `incident_category_id` bigint(20) unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incident_types_incident_category_id_foreign` (`incident_category_id`),
  CONSTRAINT `incident_types_incident_category_id_foreign` FOREIGN KEY (`incident_category_id`) REFERENCES `incident_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incidents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `incidents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `citizen_id` bigint(20) unsigned DEFAULT NULL,
  `actual_citizen_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actual_citizen_relationship` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operator_id` bigint(20) unsigned NOT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alert_level` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `citizen_location_accuracy` decimal(10,2) DEFAULT NULL,
  `citizen_altitude` decimal(10,2) DEFAULT NULL,
  `citizen_altitude_accuracy` decimal(10,2) DEFAULT NULL,
  `citizen_heading` decimal(6,2) DEFAULT NULL,
  `citizen_heading_source` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `citizen_location_captured_at` timestamp NULL DEFAULT NULL,
  `location` text COLLATE utf8mb4_unicode_ci,
  `location_road` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location_suburb` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location_barangay` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location_citymunicipality` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location_country` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `other_details` text COLLATE utf8mb4_unicode_ci,
  `called_at` timestamp NOT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incidents_status_created_at_index` (`status`,`created_at`),
  KEY `incidents_operator_id_status_index` (`operator_id`,`status`),
  KEY `incidents_status_index` (`status`),
  KEY `incidents_citizen_id_foreign` (`citizen_id`),
  CONSTRAINT `incidents_citizen_id_foreign` FOREIGN KEY (`citizen_id`) REFERENCES `users` (`id`),
  CONSTRAINT `incidents_operator_id_foreign` FOREIGN KEY (`operator_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incident_relay_outbox`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `incident_relay_outbox` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `incident_id` bigint(20) unsigned NOT NULL,
  `message_type` varchar(96) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hotline.incident.upserted',
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `pending_since` timestamp NULL DEFAULT NULL,
  `last_changed_at` timestamp NULL DEFAULT NULL,
  `attempt_count` int(10) unsigned NOT NULL DEFAULT '0',
  `last_attempted_at` timestamp NULL DEFAULT NULL,
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `incident_relay_outbox_incident_id_unique` (`incident_id`),
  KEY `incident_relay_outbox_status_index` (`status`),
  KEY `incident_relay_outbox_pending_since_index` (`pending_since`),
  KEY `incident_relay_outbox_last_changed_at_index` (`last_changed_at`),
  CONSTRAINT `incident_relay_outbox_incident_id_foreign` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incident_relay_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `incident_relay_deliveries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `incident_id` bigint(20) unsigned NOT NULL,
  `message_type` varchar(96) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hotline.incident.upserted',
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `stable_incident_key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `revision` varchar(96) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `idempotency_key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_summary_json` json DEFAULT NULL,
  `relay_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `relay_message_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deliveries_count` int(10) unsigned DEFAULT NULL,
  `attempted_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `response_json` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `incident_relay_deliveries_idempotency_key_unique` (`idempotency_key`),
  KEY `incident_relay_deliveries_status_index` (`status`),
  KEY `incident_relay_deliveries_stable_incident_key_index` (`stable_incident_key`),
  KEY `incident_relay_deliveries_relay_id_index` (`relay_id`),
  KEY `incident_relay_deliveries_relay_message_id_index` (`relay_message_id`),
  KEY `incident_relay_deliveries_incident_id_status_index` (`incident_id`,`status`),
  CONSTRAINT `incident_relay_deliveries_incident_id_foreign` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `media` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `incident_id` bigint(20) unsigned DEFAULT NULL,
  `call_session_id` bigint(20) unsigned DEFAULT NULL,
  `type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `peer_user_id` bigint(20) unsigned DEFAULT NULL,
  `peer_role` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `peer_label` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `path` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `duration_seconds` int(10) unsigned DEFAULT NULL,
  `metadata_json` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `available_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `media_call_session_id_foreign` (`call_session_id`),
  KEY `media_peer_user_id_foreign` (`peer_user_id`),
  KEY `media_incident_id_created_at_index` (`incident_id`,`created_at`),
  KEY `media_incident_id_available_at_index` (`incident_id`,`available_at`),
  KEY `media_type_created_at_index` (`type`,`created_at`),
  CONSTRAINT `media_call_session_id_foreign` FOREIGN KEY (`call_session_id`) REFERENCES `call_sessions` (`id`),
  CONSTRAINT `media_incident_id_foreign` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`),
  CONSTRAINT `media_peer_user_id_foreign` FOREIGN KEY (`peer_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `message_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `message_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `message_id` bigint(20) unsigned NOT NULL,
  `type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_filename` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_mime_type` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stored_mime_type` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stored_path` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_filename` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint(20) unsigned NOT NULL,
  `stored_size_bytes` bigint(20) unsigned DEFAULT NULL,
  `image_width` int(10) unsigned DEFAULT NULL,
  `image_height` int(10) unsigned DEFAULT NULL,
  `sha256` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `normalized_at` timestamp NULL DEFAULT NULL,
  `thumbnail_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `message_attachments_message_id_foreign` (`message_id`),
  KEY `message_attachments_uploaded_by_foreign` (`uploaded_by`),
  CONSTRAINT `message_attachments_message_id_foreign` FOREIGN KEY (`message_id`) REFERENCES `incident_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `message_attachments_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `resource_type_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `resource_type_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `sort_order` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `resource_type_categories_name_unique` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `resource_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `resource_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` bigint(20) unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_label` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `resource_types_category_id_name_unique` (`category_id`,`name`),
  CONSTRAINT `resource_types_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `resource_type_categories` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `settings` (`key`, `value`, `created_at`, `updated_at`) VALUES
  ('call_hold_seconds', '{"value":1}', NOW(), NOW()),
  ('call_timeout_seconds', '{"value":20}', NOW(), NOW()),
  ('reconnect_timeout_seconds', '{"value":20}', NOW(), NOW()),
  ('alert_level', '{"value":"Normal"}', NOW(), NOW()),
  ('alert_voice', '{"value":"default"}', NOW(), NOW()),
  ('audio_graph_style', '{"value":"tsunami"}', NOW(), NOW()),
  ('realtime_client_code', '{"value":"clt_PBB_HOTLINE"}', NOW(), NOW()),
  ('realtime_project_code_server', '{"value":"prj_HOTLINE_SERVER"}', NOW(), NOW()),
  ('realtime_project_code_caller', '{"value":"prj_HOTLINE_CITIZEN"}', NOW(), NOW()),
  ('realtime_project_code_operator', '{"value":"prj_HOTLINE_OPERATOR"}', NOW(), NOW()),
  ('realtime_project_code_command', '{"value":"prj_HOTLINE_COMMAND"}', NOW(), NOW()),
  ('realtime_project_code_media_ingest', '{"value":"prj_HOTLINE_OPERATOR"}', NOW(), NOW()),
  ('realtime_url', '{"value":"https://realtime.pbb.ph"}', NOW(), NOW()),
  ('realtime_backend_ingress_secret', '{"value":""}', NOW(), NOW()),
  ('realtime_media_ingest_secret', '{"value":""}', NOW(), NOW()),
  ('realtime_token_signing_secret', '{"value":""}', NOW(), NOW()),
  ('relay_url', '{"value":"https://relay.pbb.ph"}', NOW(), NOW()),
  ('relay_token', '{"value":""}', NOW(), NOW()),
  ('relay_source_system', '{"value":"sitrep.app"}', NOW(), NOW()),
  ('relay_target_systems', '{"value":"sitrep.ingestor"}', NOW(), NOW()),
  ('support_request_relay_source_system', '{"value":"hotline.command"}', NOW(), NOW()),
  ('support_request_relay_target_systems', '{"value":"support.dispatch"}', NOW(), NOW()),
  ('support_request_relay_handler_token', '{"value":""}', NOW(), NOW()),
  ('incident_relay_enabled', '{"value":false}', NOW(), NOW()),
  ('incident_relay_source_system', '{"value":"hotline.incident"}', NOW(), NOW()),
  ('incident_relay_target_systems', '{"value":"utility.vena"}', NOW(), NOW()),
  ('incident_relay_debounce_seconds', '{"value":10}', NOW(), NOW()),
  ('sitrep_media_access_token', '{"value":""}', NOW(), NOW()),
  ('account_admin_api_enabled', '{"value":false}', NOW(), NOW()),
  ('account_admin_api_token', '{"value":""}', NOW(), NOW()),
  ('account_admin_api_client', '{"value":"pbb-account"}', NOW(), NOW()),
  ('map_server_url', '{"value":"https://mapserver.pbb.ph"}', NOW(), NOW()),
  ('sitrep_periodic_generation_enabled', '{"value":true}', NOW(), NOW()),
  ('sitrep_periodic_normal_interval_minutes', '{"value":240}', NOW(), NOW()),
  ('sitrep_periodic_elevated_interval_minutes', '{"value":60}', NOW(), NOW()),
  ('sitrep_periodic_critical_interval_minutes', '{"value":15}', NOW(), NOW());
DROP TABLE IF EXISTS `sitrep_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sitrep_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sequence_number` int(10) unsigned NOT NULL,
  `title` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `coverage_area` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `period_started_at` timestamp NOT NULL,
  `period_ended_at` timestamp NOT NULL,
  `generated_at` timestamp NOT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `visibility` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'private',
  `alert_level` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prepared_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `reviewed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `summary_json` json DEFAULT NULL,
  `situation_json` json DEFAULT NULL,
  `damage_json` json DEFAULT NULL,
  `population_json` json DEFAULT NULL,
  `actions_json` json DEFAULT NULL,
  `needs_json` json DEFAULT NULL,
  `gaps_json` json DEFAULT NULL,
  `source_snapshot_json` json DEFAULT NULL,
  `privacy_redactions_json` json DEFAULT NULL,
  `data_quality_json` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sitrep_reports_sequence_number_unique` (`sequence_number`),
  KEY `sitrep_reports_prepared_by_user_id_foreign` (`prepared_by_user_id`),
  KEY `sitrep_reports_reviewed_by_user_id_foreign` (`reviewed_by_user_id`),
  KEY `sitrep_reports_status_visibility_index` (`status`,`visibility`),
  KEY `sitrep_reports_period_started_at_period_ended_at_index` (`period_started_at`,`period_ended_at`),
  CONSTRAINT `sitrep_reports_prepared_by_user_id_foreign` FOREIGN KEY (`prepared_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `sitrep_reports_reviewed_by_user_id_foreign` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sitrep_relay_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sitrep_relay_deliveries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sitrep_report_id` bigint(20) unsigned NOT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `attempt_count` int(10) unsigned NOT NULL DEFAULT '0',
  `relay_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `relay_message_id` varchar(64) DEFAULT NULL,
  `deliveries_count` int(10) unsigned DEFAULT NULL,
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `last_attempted_at` timestamp NULL DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `response_json` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sitrep_relay_deliveries_sitrep_report_id_unique` (`sitrep_report_id`),
  KEY `sitrep_relay_deliveries_status_index` (`status`),
  KEY `sitrep_relay_deliveries_relay_id_index` (`relay_id`),
  CONSTRAINT `sitrep_relay_deliveries_sitrep_report_id_foreign` FOREIGN KEY (`sitrep_report_id`) REFERENCES `sitrep_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `support_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `local_request_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `correlation_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `support_request_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'requested',
  `relay_delivery_status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `relay_attempt_count` int(10) unsigned NOT NULL DEFAULT '0',
  `relay_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `relay_message_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `relay_deliveries_count` int(10) unsigned DEFAULT NULL,
  `relay_last_error` text COLLATE utf8mb4_unicode_ci,
  `relay_last_attempted_at` timestamp NULL DEFAULT NULL,
  `relay_submitted_at` timestamp NULL DEFAULT NULL,
  `relay_response_json` json DEFAULT NULL,
  `urgency` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `requested_assistance` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `requested_capability` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int(10) unsigned DEFAULT NULL,
  `quantity_unit` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `justification_codes` json DEFAULT NULL,
  `justification_labels` json DEFAULT NULL,
  `staging_notes` text COLLATE utf8mb4_unicode_ci,
  `command_notes` text COLLATE utf8mb4_unicode_ci,
  `requester_user_id` bigint(20) unsigned DEFAULT NULL,
  `requester_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requester_role` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_system` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hotline.command',
  `source_hub_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_relay_hub_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_hub_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_snapshot_json` json DEFAULT NULL,
  `sitrep_report_id` bigint(20) unsigned DEFAULT NULL,
  `sitrep_sequence_number` int(10) unsigned DEFAULT NULL,
  `sitrep_generated_at` timestamp NULL DEFAULT NULL,
  `sitrep_section` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sitrep_evidence_ref` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gap_json` json DEFAULT NULL,
  `evidence_row_json` json DEFAULT NULL,
  `incident_refs_json` json DEFAULT NULL,
  `selected_incident_ids_json` json DEFAULT NULL,
  `support_context_json` json DEFAULT NULL,
  `requested_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `support_requests_local_request_id_unique` (`local_request_id`),
  UNIQUE KEY `support_requests_correlation_id_unique` (`correlation_id`),
  KEY `support_requests_support_request_id_index` (`support_request_id`),
  KEY `support_requests_status_index` (`status`),
  KEY `support_requests_relay_delivery_status_index` (`relay_delivery_status`),
  KEY `support_requests_relay_id_index` (`relay_id`),
  KEY `support_requests_relay_message_id_index` (`relay_message_id`),
  KEY `support_requests_urgency_index` (`urgency`),
  KEY `support_requests_requester_user_id_foreign` (`requester_user_id`),
  KEY `support_requests_sitrep_report_id_foreign` (`sitrep_report_id`),
  KEY `support_requests_sitrep_report_id_sitrep_section_index` (`sitrep_report_id`,`sitrep_section`),
  KEY `support_requests_requested_at_index` (`requested_at`),
  KEY `support_requests_status_relay_delivery_status_index` (`status`,`relay_delivery_status`),
  CONSTRAINT `support_requests_requester_user_id_foreign` FOREIGN KEY (`requester_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `support_requests_sitrep_report_id_foreign` FOREIGN KEY (`sitrep_report_id`) REFERENCES `sitrep_reports` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `support_request_histories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_request_histories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `support_request_id` bigint(20) unsigned NOT NULL,
  `event_type` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `relay_message_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `update_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `support_request_external_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_system` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actor_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` text COLLATE utf8mb4_unicode_ci,
  `payload_json` json DEFAULT NULL,
  `occurred_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `support_request_histories_request_relay_unique` (`support_request_id`,`relay_message_id`),
  UNIQUE KEY `support_request_histories_request_update_unique` (`support_request_id`,`update_id`),
  KEY `support_request_histories_support_request_id_foreign` (`support_request_id`),
  KEY `support_request_histories_event_type_index` (`event_type`),
  KEY `support_request_histories_status_index` (`status`),
  KEY `support_request_histories_relay_message_id_index` (`relay_message_id`),
  KEY `support_request_histories_update_id_index` (`update_id`),
  KEY `support_request_histories_support_request_external_id_index` (`support_request_external_id`),
  KEY `support_request_histories_occurred_at_index` (`occurred_at`),
  CONSTRAINT `support_request_histories_support_request_id_foreign` FOREIGN KEY (`support_request_id`) REFERENCES `support_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `team_assignment_allocated_resources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `team_assignment_allocated_resources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `team_assignment_id` bigint(20) unsigned NOT NULL,
  `resource_type_id` bigint(20) unsigned NOT NULL,
  `quantity_allocated` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `team_assignment_allocated_resources_team_assignment_id_foreign` (`team_assignment_id`),
  KEY `team_assignment_allocated_resources_resource_type_id_foreign` (`resource_type_id`),
  CONSTRAINT `team_assignment_allocated_resources_resource_type_id_foreign` FOREIGN KEY (`resource_type_id`) REFERENCES `resource_types` (`id`),
  CONSTRAINT `team_assignment_allocated_resources_team_assignment_id_foreign` FOREIGN KEY (`team_assignment_id`) REFERENCES `team_assignments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `team_assignment_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `team_assignment_notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `team_assignment_id` bigint(20) unsigned NOT NULL,
  `created_by_operator_id` bigint(20) unsigned NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `team_assignment_notes_team_assignment_id_foreign` (`team_assignment_id`),
  KEY `team_assignment_notes_created_by_operator_id_foreign` (`created_by_operator_id`),
  CONSTRAINT `team_assignment_notes_created_by_operator_id_foreign` FOREIGN KEY (`created_by_operator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `team_assignment_notes_team_assignment_id_foreign` FOREIGN KEY (`team_assignment_id`) REFERENCES `team_assignments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `team_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `team_assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `incident_id` bigint(20) unsigned NOT NULL,
  `team_id` bigint(20) unsigned NOT NULL,
  `assigned_by_operator_id` bigint(20) unsigned NOT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_person` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancelled_from_status` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancel_reason_code` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancel_reason_note` text COLLATE utf8mb4_unicode_ci,
  `cancelled_by_operator_id` bigint(20) unsigned DEFAULT NULL,
  `assigned_at` timestamp NOT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `enroute_at` timestamp NULL DEFAULT NULL,
  `arrived_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `team_assignments_incident_id_team_id_unique` (`incident_id`,`team_id`),
  KEY `team_assignments_team_id_foreign` (`team_id`),
  KEY `team_assignments_assigned_by_operator_id_foreign` (`assigned_by_operator_id`),
  KEY `team_assignments_cancelled_by_operator_id_foreign` (`cancelled_by_operator_id`),
  CONSTRAINT `team_assignments_assigned_by_operator_id_foreign` FOREIGN KEY (`assigned_by_operator_id`) REFERENCES `users` (`id`),
  CONSTRAINT `team_assignments_cancelled_by_operator_id_foreign` FOREIGN KEY (`cancelled_by_operator_id`) REFERENCES `users` (`id`),
  CONSTRAINT `team_assignments_incident_id_foreign` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`),
  CONSTRAINT `team_assignments_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `team_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `team_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `sort_order` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `team_resource_inventories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `team_resource_inventories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `team_id` bigint(20) unsigned NOT NULL,
  `resource_type_id` bigint(20) unsigned NOT NULL,
  `quantity_available` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `team_resource_inventories_team_id_foreign` (`team_id`),
  KEY `team_resource_inventories_resource_type_id_foreign` (`resource_type_id`),
  CONSTRAINT `team_resource_inventories_resource_type_id_foreign` FOREIGN KEY (`resource_type_id`) REFERENCES `resource_types` (`id`),
  CONSTRAINT `team_resource_inventories_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `teams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teams` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `team_category_id` bigint(20) unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `teams_team_category_id_foreign` (`team_category_id`),
  CONSTRAINT `teams_team_category_id_foreign` FOREIGN KEY (`team_category_id`) REFERENCES `team_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `avatar_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pbb_user_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `last_login_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_pbb_user_id_unique` (`pbb_user_id`),
  KEY `users_role_index` (`role`),
  KEY `users_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fallback_incident_drops`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fallback_incident_drops` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `citizen_id` bigint(20) unsigned NOT NULL,
  `claimed_by_operator_id` bigint(20) unsigned DEFAULT NULL,
  `converted_incident_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `reason` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all_operators_busy',
  `citizen_latitude` decimal(10,7) DEFAULT NULL,
  `citizen_longitude` decimal(10,7) DEFAULT NULL,
  `citizen_location_accuracy` decimal(10,2) DEFAULT NULL,
  `quick_category` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `short_description` text COLLATE utf8mb4_unicode_ci,
  `contact_snapshot` json DEFAULT NULL,
  `closure_disposition` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `closure_note` text COLLATE utf8mb4_unicode_ci,
  `claimed_at` timestamp NULL DEFAULT NULL,
  `converted_at` timestamp NULL DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fallback_incident_drops_status_index` (`status`),
  KEY `fallback_incident_drops_reason_index` (`reason`),
  KEY `fallback_incident_drops_citizen_id_status_index` (`citizen_id`,`status`),
  KEY `fallback_incident_drops_claimed_by_operator_id_status_index` (`claimed_by_operator_id`,`status`),
  KEY `fallback_incident_drops_converted_incident_id_foreign` (`converted_incident_id`),
  CONSTRAINT `fallback_incident_drops_citizen_id_foreign` FOREIGN KEY (`citizen_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fallback_incident_drops_claimed_by_operator_id_foreign` FOREIGN KEY (`claimed_by_operator_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fallback_incident_drops_converted_incident_id_foreign` FOREIGN KEY (`converted_incident_id`) REFERENCES `incidents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fallback_incident_drop_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fallback_incident_drop_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fallback_incident_drop_id` bigint(20) unsigned NOT NULL,
  `type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'image',
  `original_filename` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `original_mime_type` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stored_mime_type` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'image/webp',
  `stored_path` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_filename` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_size_bytes` bigint(20) unsigned DEFAULT NULL,
  `stored_size_bytes` bigint(20) unsigned NOT NULL,
  `image_width` int(10) unsigned DEFAULT NULL,
  `image_height` int(10) unsigned DEFAULT NULL,
  `sha256` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `normalized_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fallback_incident_drop_attachments_sha256_index` (`sha256`),
  KEY `fallback_incident_drop_attachments_drop_id_type_index` (`fallback_incident_drop_id`,`type`),
  CONSTRAINT `fallback_incident_drop_attachments_drop_id_foreign` FOREIGN KEY (`fallback_incident_drop_id`) REFERENCES `fallback_incident_drops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fallback_incident_drop_histories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fallback_incident_drop_histories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fallback_incident_drop_id` bigint(20) unsigned NOT NULL,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `event` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_status` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fallback_incident_drop_histories_drop_created_at_index` (`fallback_incident_drop_id`,`created_at`),
  KEY `fallback_incident_drop_histories_event_created_at_index` (`event`,`created_at`),
  KEY `fallback_incident_drop_histories_actor_id_foreign` (`actor_id`),
  CONSTRAINT `fallback_incident_drop_histories_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fallback_incident_drop_histories_drop_id_foreign` FOREIGN KEY (`fallback_incident_drop_id`) REFERENCES `fallback_incident_drops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


-- PBB Hotline installer baseline migration ledger.

--
-- Packaged Hotline reference data
-- Source: resources/data/hotline/reference-data.json
--

INSERT INTO `incident_categories` (`id`, `name`, `description`, `sort_order`, `created_at`, `updated_at`) VALUES
  (1, 'Medical & Rescue', 'Health emergencies, rescue, and extraction concerns.', 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (2, 'Fire & Hazard', 'Structure fires, vehicle fires, and immediate hazard control.', 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (3, 'Crime & Security', 'Security incidents, threats, theft, and investigation needs.', 3, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (4, 'Disaster & Weather', 'Floods, earthquakes, landslides, and disaster impacts.', 4, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (5, 'Traffic / Road Safety', 'Road crashes, blocked routes, and transport safety incidents.', 5, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (6, 'Infrastructure & Utilities', 'Utility interruptions and public infrastructure damage.', 6, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (7, 'Protection / Safety', 'Domestic safety, vulnerable-person, and welfare-sensitive concerns.', 7, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (8, 'Public Disturbance', 'Public-order incidents, crowd unrest, and community disturbances.', 8, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (9, 'Other', 'Concerns requiring operator assessment that do not fit another category.', 9, '2026-08-06 00:00:00', '2026-08-06 00:00:00');

INSERT INTO `resource_type_categories` (`id`, `name`, `description`, `sort_order`, `created_at`, `updated_at`) VALUES
  (1, 'Medical Response', 'Medical transport, response teams, and treatment supplies.', 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (2, 'Food and Relief Supplies', 'Food, water, and relief supplies for affected families.', 3, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (3, 'Water and Sanitation', 'Potable water, sanitation, and hygiene support resources.', 4, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (4, 'Shelter Support', 'Temporary shelter and shelter support materials.', 6, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (5, 'Rescue and Extraction', 'Water, rope, and technical rescue or extraction resources.', 7, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (6, 'Welfare / Social Services', 'Social welfare and family support responders.', 11, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (7, 'Non-food Items', 'Household and personal support kits.', 13, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (8, 'Search and Damage Assessment', 'Search, field assessment, and damage assessment resources.', 16, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (9, 'Aerial / Field Reconnaissance', 'Aerial, field, and remote observation resources.', 17, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (10, 'Fire / Water Tanker Support', 'Fire suppression and water tanker support resources.', 18, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (11, 'Public Safety / Traffic Control', 'Law enforcement, traffic control, perimeter, and public-order resources.', 19, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (12, 'Specialized Rescue Equipment', 'Specialized rescue, extrication, access, and field support tools.', 20, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (13, 'Utility Restoration', 'Utility repair and service restoration resources.', 24, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (14, 'Heavy Equipment / Clearing', 'Heavy equipment for debris, road, and structural clearing operations.', 28, '2026-08-06 00:00:00', '2026-08-06 00:00:00');

INSERT INTO `team_categories` (`id`, `name`, `description`, `sort_order`, `created_at`, `updated_at`) VALUES
  (1, 'Medical', 'Emergency medical and patient transport teams.', 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (2, 'Fire', 'Fire suppression and hazard-control teams.', 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (3, 'Rescue', 'Technical rescue, search, and evacuation teams.', 3, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (4, 'Public Safety', 'Police, traffic, and public-order response teams.', 4, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (5, 'Welfare', 'Social welfare and relief support teams.', 5, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (6, 'Utilities', 'Utility repair and infrastructure response teams.', 6, '2026-08-06 00:00:00', '2026-08-06 00:00:00');

INSERT INTO `incident_types` (`id`, `incident_category_id`, `name`, `description`, `created_at`, `updated_at`) VALUES
  (1, 1, 'Animal Attack', 'Animal bite, attack, or aggressive animal situation requiring medical help, containment, or responder support.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (2, 1, 'Medical Emergency', 'Urgent health concern needing medical response.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (3, 1, 'Rescue', 'Person needing rescue or extraction from danger.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (4, 2, 'Building/House Fire', 'Fire affecting a house, building, or other structure.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (5, 2, 'Vehicle Fire', 'Fire involving a vehicle, road access, or casualty concern.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (6, 3, 'Bomb Threat', 'Reported bomb threat, suspicious package, or explosive-related warning.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (7, 3, 'Home Intrusion', 'Unauthorized entry or active threat inside a home or private property.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (8, 3, 'Kidnapping', 'Suspected abduction, forced taking, or unlawful detention of a person.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (9, 3, 'Missing Person', 'Person whose whereabouts are unknown and may need search or welfare check.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (10, 3, 'Robbery', 'Theft involving force, threat, or intimidation.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (11, 3, 'Vehicle Theft / Carnapping', 'Stolen or forcibly taken vehicle report.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (12, 4, 'Earthquake', 'Earthquake-related impacts such as damaged infrastructure or trapped persons.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (13, 4, 'Family Displacement', 'Families displaced or needing welfare support after a hazard.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (14, 4, 'Flood', 'Flooding, rising water, or water intrusion affecting roads, homes, or communities.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (15, 4, 'Landslide', 'Soil, rock, or debris movement affecting people, homes, roads, or infrastructure.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (16, 4, 'Shelter Damage', 'Damage to a house, shelter, or occupied structure.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (17, 5, 'Road Accident', 'Crash or road incident involving vehicles, pedestrians, injuries, or obstruction.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (18, 6, 'Infrastructure Damage', 'Damage to roads, bridges, drainage, utilities, public buildings, or facilities.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (19, 6, 'Power Outage', 'Electrical service interruption or unsafe power condition.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (20, 6, 'Water Supply Issue', 'Water interruption, dirty water, low pressure, leak, or potable water concern.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (21, 7, 'Domestic Violence', 'Violence, abuse, or immediate safety concern within a household or relationship.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (22, 8, 'Public Disturbance / Riot', 'Public disturbance, violent crowd incident, road obstruction, or escalating group conflict.', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (23, 9, 'Other Concern', 'Incident or community concern that needs operator assessment.', '2026-08-06 00:00:00', '2026-08-06 00:00:00');

INSERT INTO `resource_types` (`id`, `category_id`, `name`, `unit_label`, `created_at`, `updated_at`) VALUES
  (1, 1, 'Ambulance', 'unit', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (2, 1, 'Medical Response Team', 'team', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (3, 1, 'Medical Supplies', 'kit', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (4, 2, 'Food & Water Supplies', 'pack', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (5, 3, 'Potable Water', 'container', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (6, 4, 'Tents', 'unit', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (7, 5, 'Rescue Team', 'team', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (8, 5, 'Rope', 'roll', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (9, 5, 'Life Vest', 'piece', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (10, 5, 'LifeBuoy Ring', 'piece', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (11, 5, 'Body Harness', 'set', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (12, 5, 'Harness Cable', 'set', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (13, 6, 'Social Welfare Team', 'team', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (14, 7, 'Hygiene Kit', 'kit', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (15, 7, 'Sleeping Kit', 'kit', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (16, 7, 'Kitchen Kit', 'kit', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (17, 7, 'Family Clothing Kit', 'kit', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (18, 8, 'Search Team', 'team', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (19, 8, 'Assessment Team', 'team', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (20, 8, 'Structural Assessment Team', 'team', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (21, 8, 'Search Lights', 'set', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (22, 9, 'Drone', 'unit', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (23, 9, 'Satellite Phone', 'unit', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (24, 10, 'Fire Truck', 'unit', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (25, 10, 'Water Tanker', 'unit', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (26, 11, 'Animal Control Team', 'team', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (27, 11, 'Police Unit', 'unit', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (28, 11, 'Investigation Team', 'team', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (29, 11, 'Crowd Control Team', 'team', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (30, 11, 'Negotiator', 'person', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (31, 11, 'Perimeter Barrier', 'set', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (32, 12, 'Hydraulic Combi-tool', 'unit', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (33, 12, 'Hydraulic Power Pack', 'unit', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (34, 13, 'Utility Repair Team', 'team', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (35, 14, 'Tow Truck', 'unit', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (36, 14, 'Backhoe', 'unit', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (37, 14, 'Dump Truck', 'unit', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (38, 14, 'Road Breaker', 'unit', '2026-08-06 00:00:00', '2026-08-06 00:00:00');

INSERT INTO `teams` (`id`, `team_category_id`, `name`, `status`, `created_at`, `updated_at`) VALUES
  (1, 1, 'Barangay EMS Team', 'active', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (2, 2, 'Barangay Fire Response Team', 'active', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (3, 3, 'Barangay Rescue Team', 'active', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (4, 3, 'Search and Assessment Team', 'active', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (5, 4, 'Barangay Public Safety Team', 'active', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (6, 4, 'Crowd Control Team', 'active', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (7, 5, 'Social Welfare Response Team', 'active', '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (8, 6, 'Utilities Response Team', 'active', '2026-08-06 00:00:00', '2026-08-06 00:00:00');

INSERT INTO `incident_type_fields` (`id`, `incident_type_id`, `field_key`, `field_label`, `input_type`, `options_json`, `config_json`, `default_value`, `placeholder`, `unit`, `is_required`, `sort_order`, `min`, `max`, `step`, `created_at`, `updated_at`) VALUES
  (1, 2, 'patient_details', 'Patient Details', 'group', NULL, '{"preset":"casualtyPatient"}', NULL, NULL, NULL, 0, 20, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (2, 1, 'patient_details', 'Patient Details', 'group', NULL, '{"preset":"casualtyPatient"}', NULL, NULL, NULL, 0, 20, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (3, 3, 'patient_details', 'Patient Details', 'group', NULL, '{"preset":"casualtyPatient"}', NULL, NULL, NULL, 0, 20, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (4, 5, 'vehicles_involved', 'Vehicles Involved', 'group', NULL, '{"preset":"vehicleInvolved"}', NULL, NULL, NULL, 0, 20, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (5, 5, 'road_access', 'Road / Access Status', 'group', NULL, '{"preset":"roadAccessStatus"}', NULL, NULL, NULL, 0, 21, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (6, 5, 'patient_details', 'Patient Details', 'group', NULL, '{"preset":"casualtyPatient"}', NULL, NULL, NULL, 0, 22, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (7, 17, 'vehicles_involved', 'Vehicles Involved', 'group', NULL, '{"preset":"vehicleInvolved"}', NULL, NULL, NULL, 0, 20, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (8, 17, 'patient_details', 'Patient Details', 'group', NULL, '{"preset":"casualtyPatient"}', NULL, NULL, NULL, 0, 21, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (9, 17, 'road_access', 'Road / Access Status', 'group', NULL, '{"preset":"roadAccessStatus"}', NULL, NULL, NULL, 0, 22, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (10, 14, 'road_access', 'Road / Access Status', 'group', NULL, '{"preset":"roadAccessStatus"}', NULL, NULL, NULL, 0, 20, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (11, 12, 'infrastructure_damage', 'Infrastructure Damage', 'group', NULL, '{"preset":"infrastructureDamage"}', NULL, NULL, NULL, 0, 20, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (12, 12, 'road_access', 'Road / Access Status', 'group', NULL, '{"preset":"roadAccessStatus"}', NULL, NULL, NULL, 0, 21, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (13, 15, 'infrastructure_damage', 'Infrastructure Damage', 'group', NULL, '{"preset":"infrastructureDamage"}', NULL, NULL, NULL, 0, 20, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (14, 15, 'road_access', 'Road / Access Status', 'group', NULL, '{"preset":"roadAccessStatus"}', NULL, NULL, NULL, 0, 21, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (15, 18, 'infrastructure_damage_details', 'Infrastructure Damage Details', 'group', NULL, '{"preset":"infrastructureDamage"}', NULL, NULL, NULL, 0, 20, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (16, 18, 'road_access', 'Road / Access Status', 'group', NULL, '{"preset":"roadAccessStatus"}', NULL, NULL, NULL, 0, 21, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (17, 11, 'vehicles_involved', 'Vehicles Involved', 'group', NULL, '{"preset":"vehicleInvolved"}', NULL, NULL, NULL, 0, 20, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (18, 8, 'vehicles_involved', 'Vehicles Involved', 'group', NULL, '{"preset":"vehicleInvolved"}', NULL, NULL, NULL, 0, 20, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (19, 8, 'victim_details', 'Victim Details', 'group', NULL, '{"preset":"person"}', NULL, NULL, NULL, 0, 21, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (20, 10, 'vehicles_involved', 'Vehicles Involved', 'group', NULL, '{"preset":"vehicleInvolved"}', NULL, NULL, NULL, 0, 20, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (21, 16, 'shelter_damage', 'Shelter Damage Details', 'group', NULL, '{"preset":"shelterDamage"}', NULL, NULL, NULL, 0, 1, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (22, 13, 'affected_families', 'Affected Families', 'group', NULL, '{"preset":"family"}', NULL, NULL, NULL, 0, 1, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (23, 22, 'disturbance_type', 'Disturbance type', 'select', '["Verbal conflict","Physical fight","Crowd unrest","Property damage","Road obstruction","Other"]', NULL, NULL, NULL, NULL, 0, 2, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (24, 22, 'weapons_seen', 'Weapons seen', 'select', '["Unknown","No","Yes"]', NULL, NULL, NULL, NULL, 0, 3, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (25, 22, 'immediate_danger', 'Immediate danger', 'select', '["Unknown","No","Yes"]', NULL, NULL, NULL, NULL, 0, 4, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (26, 22, 'access_blocked', 'Road or access blocked', 'select', '["Unknown","No","Yes"]', NULL, NULL, NULL, NULL, 0, 5, NULL, NULL, NULL, '2026-08-06 00:00:00', '2026-08-06 00:00:00');

INSERT INTO `incident_type_default_resources` (`id`, `incident_type_id`, `resource_type_id`, `quantity_required`, `notes`, `sort_order`, `created_at`, `updated_at`) VALUES
  (1, 1, 1, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (2, 1, 26, 1, NULL, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (3, 2, 1, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (4, 2, 2, 1, NULL, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (5, 2, 3, 1, NULL, 3, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (6, 3, 7, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (7, 3, 1, 1, NULL, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (8, 3, 8, 1, NULL, 3, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (9, 3, 11, 1, NULL, 4, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (10, 3, 12, 1, NULL, 5, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (11, 4, 24, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (12, 4, 25, 1, NULL, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (13, 5, 24, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (14, 5, 35, 1, NULL, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (15, 14, 8, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (16, 14, 9, 1, NULL, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (17, 14, 10, 1, NULL, 3, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (18, 12, 22, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (19, 12, 23, 1, NULL, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (20, 15, 36, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (21, 15, 37, 1, NULL, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (22, 15, 22, 1, NULL, 3, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (23, 17, 1, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (24, 17, 32, 1, NULL, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (25, 17, 33, 1, NULL, 3, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (26, 18, 19, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (27, 18, 36, 1, NULL, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (28, 18, 37, 1, NULL, 3, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (29, 18, 38, 1, NULL, 4, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (30, 16, 20, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (31, 16, 6, 1, NULL, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (32, 16, 15, 1, NULL, 3, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (33, 16, 14, 1, NULL, 4, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (34, 16, 17, 1, NULL, 5, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (35, 13, 13, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (36, 13, 4, 1, NULL, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (37, 13, 14, 1, NULL, 3, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (38, 13, 15, 1, NULL, 4, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (39, 13, 16, 1, NULL, 5, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (40, 13, 17, 1, NULL, 6, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (41, 13, 6, 1, NULL, 7, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (42, 9, 18, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (43, 9, 22, 1, NULL, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (44, 9, 21, 1, NULL, 3, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (45, 6, 27, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (46, 6, 31, 1, NULL, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (47, 6, 28, 1, NULL, 3, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (48, 7, 27, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (49, 8, 27, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (50, 8, 28, 1, NULL, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (51, 8, 30, 1, NULL, 3, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (52, 10, 27, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (53, 10, 28, 1, NULL, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (54, 11, 27, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (55, 11, 28, 1, NULL, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (56, 21, 27, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (57, 21, 13, 1, NULL, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (58, 22, 27, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (59, 22, 29, 1, NULL, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (60, 22, 31, 1, NULL, 3, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (61, 19, 34, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (62, 20, 34, 1, NULL, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (63, 20, 5, 1, NULL, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (64, 20, 25, 1, NULL, 3, '2026-08-06 00:00:00', '2026-08-06 00:00:00');

INSERT INTO `team_resource_inventories` (`id`, `team_id`, `resource_type_id`, `quantity_available`, `created_at`, `updated_at`) VALUES
  (1, 1, 1, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (2, 1, 2, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (3, 1, 3, 4, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (4, 2, 24, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (5, 2, 25, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (6, 3, 7, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (7, 3, 8, 3, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (8, 3, 9, 12, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (9, 3, 10, 4, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (10, 3, 11, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (11, 3, 12, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (12, 4, 18, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (13, 4, 19, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (14, 4, 22, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (15, 4, 21, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (16, 5, 27, 2, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (17, 5, 28, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (18, 5, 31, 4, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (19, 6, 29, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (20, 6, 31, 6, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (21, 7, 13, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (22, 7, 4, 20, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (23, 7, 14, 20, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (24, 7, 15, 20, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (25, 8, 34, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (26, 8, 5, 10, '2026-08-06 00:00:00', '2026-08-06 00:00:00'),
  (27, 8, 25, 1, '2026-08-06 00:00:00', '2026-08-06 00:00:00');

ALTER TABLE `incident_categories` AUTO_INCREMENT=10;
ALTER TABLE `resource_type_categories` AUTO_INCREMENT=15;
ALTER TABLE `team_categories` AUTO_INCREMENT=7;
ALTER TABLE `incident_types` AUTO_INCREMENT=24;
ALTER TABLE `resource_types` AUTO_INCREMENT=39;
ALTER TABLE `teams` AUTO_INCREMENT=9;
ALTER TABLE `incident_type_fields` AUTO_INCREMENT=27;
ALTER TABLE `incident_type_default_resources` AUTO_INCREMENT=65;
ALTER TABLE `team_resource_inventories` AUTO_INCREMENT=28;

INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('0001_01_01_000000_create_users_table', 1),
  ('0001_01_01_000001_create_cache_table', 1),
  ('0001_01_01_000002_create_jobs_table', 1),
  ('2026_04_04_000001_create_settings_table', 1),
  ('2026_04_04_000002_create_incident_categories_table', 1),
  ('2026_04_04_000003_create_incident_types_table', 1),
  ('2026_04_04_000004_create_incident_type_fields_table', 1),
  ('2026_04_04_000005_create_team_categories_table', 1),
  ('2026_04_04_000006_create_resource_types_table', 1),
  ('2026_04_04_000007_create_teams_table', 1),
  ('2026_04_04_000008_create_team_resource_inventories_table', 1),
  ('2026_04_04_000009_create_incidents_table', 1),
  ('2026_04_04_000010_create_call_attempts_table', 1),
  ('2026_04_04_000011_create_call_attempt_operator_attempts_table', 1),
  ('2026_04_04_000012_create_call_sessions_table', 1),
  ('2026_04_04_000013_create_call_participants_table', 1),
  ('2026_04_04_000014_create_incident_messages_table', 1),
  ('2026_04_04_000015_create_message_attachments_table', 1),
  ('2026_04_04_000016_create_media_table', 1),
  ('2026_04_04_000017_create_incident_type_default_resources_table', 1),
  ('2026_04_04_000018_create_incident_type_details_table', 1),
  ('2026_04_04_000019_create_incident_resources_needed_table', 1),
  ('2026_04_04_000020_create_team_assignments_table', 1),
  ('2026_04_04_000021_create_team_assignment_allocated_resources_table', 1),
  ('2026_04_04_000022_create_incident_transfers_table', 1),
  ('2026_04_04_000023_create_activity_logs_table', 1),
  ('2026_04_06_000002_alter_incident_type_default_resources_table_add_sort_order', 1),
  ('2026_04_13_000001_drop_sender_snapshot_columns_from_incident_messages_table', 1),
  ('2026_04_18_020000_add_fractional_precision_to_call_session_timestamps', 1),
  ('2026_04_18_163000_normalize_team_assignment_status_values', 1),
  ('2026_04_18_170000_create_incident_incident_type_table', 1),
  ('2026_04_18_171000_alter_incident_resources_needed_table_add_incident_type_id', 1),
  ('2026_04_18_172000_create_team_assignment_notes_table', 1),
  ('2026_04_18_173000_alter_team_assignments_table_add_cancel_reason_note', 1),
  ('2026_04_28_000001_add_live_location_fields_to_incidents_table', 1),
  ('2026_04_28_000002_create_incident_caller_locations_table', 1),
  ('2026_04_29_000001_create_sitrep_reports_table', 1),
  ('2026_05_06_000001_create_command_broadcasts_table', 1),
  ('2026_05_06_000002_add_target_roles_to_command_broadcasts_table', 1),
  ('2026_05_08_000001_add_config_json_to_incident_type_fields', 1),
  ('2026_05_08_000002_remove_group_preset_fields_from_config_json', 1),
  ('2026_05_09_000001_rename_caller_role_to_citizen', 1),
  ('2026_05_10_000001_add_citizen_id_columns_for_caller_compatibility', 1),
  ('2026_05_11_000001_add_citizen_detail_columns_to_incidents_table', 1),
  ('2026_05_11_000002_create_incident_citizen_locations_table', 1),
  ('2026_05_11_000003_migrate_caller_protocol_values_to_citizen', 1),
  ('2026_05_11_000004_drop_caller_storage_columns', 1),
  ('2026_05_13_000001_refactor_incident_types_for_group_presets', 1),
  ('2026_05_13_000002_refactor_resource_defaults_for_incident_types', 1),
  ('2026_05_30_000001_create_sitrep_relay_deliveries_table', 1),
  ('2026_05_31_034100_change_sitrep_relay_message_id_to_string', 1),
  ('2026_06_11_000001_create_support_requests_table', 1),
  ('2026_06_11_000002_create_support_request_histories_table', 1),
  ('2026_06_12_000001_add_justifications_to_support_requests_table', 1),
  ('2026_06_12_000002_add_scope_context_to_support_requests_table', 1),
  ('2026_06_29_000001_add_pbb_user_id_to_users_table', 1),
  ('2026_07_06_000001_create_incident_relay_outbox_table', 1),
  ('2026_07_06_000002_create_incident_relay_deliveries_table', 1),
  ('2026_07_28_000001_add_normalized_image_metadata_to_message_attachments_table', 1),
  ('2026_08_07_000001_allow_diagnostic_media_records', 1),
  ('2026_08_14_000001_create_fallback_incident_drops_table', 1),
  ('2026_08_14_000002_create_fallback_incident_drop_attachments_table', 1),
  ('2026_08_14_000003_create_fallback_incident_drop_histories_table', 1);
