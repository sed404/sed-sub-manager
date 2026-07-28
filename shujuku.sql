-- MySQL dump 10.13  Distrib 5.7.44, for Linux (x86_64)
--
-- Host: localhost    Database: clash
-- ------------------------------------------------------
-- Server version	5.7.44-log

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `admins`
--

DROP TABLE IF EXISTS `admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admins` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(60) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admins`
--

LOCK TABLES `admins` WRITE;
/*!40000 ALTER TABLE `admins` DISABLE KEYS */;
INSERT INTO `admins` VALUES (1,'admin','$2y$10$FTG1doROiKMhOMFh9CALOeRPcM9zze4.DkZ8CdoA0mrjlgr5o.Fxi','2026-06-15 23:11:29');
/*!40000 ALTER TABLE `admins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cards`
--

DROP TABLE IF EXISTS `cards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cards` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `card_key` varchar(32) NOT NULL,
  `duration_days` int(10) unsigned NOT NULL,
  `valid_until` datetime DEFAULT NULL,
  `status` enum('unused','used','disabled') NOT NULL DEFAULT 'unused',
  `used_by` int(10) unsigned DEFAULT NULL,
  `used_time` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `card_key` (`card_key`),
  KEY `idx_cards_status` (`status`),
  KEY `idx_cards_used_by` (`used_by`)
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cards`
--

LOCK TABLES `cards` WRITE;
/*!40000 ALTER TABLE `cards` DISABLE KEYS */;
INSERT INTO `cards` VALUES (1,'52BB-E3E0-0788',30,NULL,'used',1,'2026-06-15 23:15:54','2026-06-15 23:12:09'),(2,'5957-46D8-3426',30,NULL,'used',2,'2026-06-16 00:04:01','2026-06-16 00:03:21'),(3,'C144-47A4-3AD4',365,NULL,'unused',NULL,NULL,'2026-06-16 03:03:04'),(4,'4085-F68D-5818',365,NULL,'unused',NULL,NULL,'2026-06-16 03:03:04'),(5,'F900-ACC1-79CB',365,NULL,'unused',NULL,NULL,'2026-06-16 03:03:04'),(6,'CCFE-BD4F-710B',365,NULL,'unused',NULL,NULL,'2026-06-16 03:03:04'),(7,'F6A0-DEF2-3317',365,NULL,'unused',NULL,NULL,'2026-06-16 03:03:04'),(8,'7238-CF48-CB5C',365,NULL,'unused',NULL,NULL,'2026-06-16 03:03:04'),(9,'C6BF-71D1-2D8A',365,NULL,'unused',NULL,NULL,'2026-06-16 03:03:04'),(10,'B9BD-A419-2252',365,NULL,'used',3,'2026-06-16 07:12:07','2026-06-16 07:02:47'),(11,'ABB9-B0F7-19DA',1000,NULL,'used',4,'2026-06-16 18:37:20','2026-06-16 18:36:46'),(12,'8BE2-D543-8039',1000,NULL,'used',9,'2026-06-17 22:54:43','2026-06-16 18:36:46'),(13,'538C-2171-75BE',1000,NULL,'unused',NULL,NULL,'2026-06-16 18:36:46'),(14,'F7E2-4DC1-80B2',1000,NULL,'unused',NULL,NULL,'2026-06-16 18:36:46'),(15,'07B3-9331-8978',1000,NULL,'unused',NULL,NULL,'2026-06-16 18:36:46'),(16,'AF93-D405-FE74',395,NULL,'used',6,'2026-06-16 21:05:35','2026-06-16 18:45:28'),(17,'363D-8003-E7B1',312,NULL,'used',5,'2026-06-16 20:57:28','2026-06-16 20:55:12'),(18,'C2DB-6865-6743',365,NULL,'unused',NULL,NULL,'2026-06-16 20:58:49'),(19,'7F9E-8936-C06D',30,NULL,'used',7,'2026-06-16 23:47:02','2026-06-16 23:46:07'),(20,'2307-CF62-1212',365,NULL,'used',8,'2026-06-17 02:10:56','2026-06-17 02:10:19'),(21,'BBFF-8ADA-3F5E',365,NULL,'unused',NULL,NULL,'2026-06-18 00:35:24'),(22,'125A-A7AF-BB51',365,NULL,'used',10,'2026-06-18 02:16:55','2026-06-18 01:51:32'),(23,'87F1-79BB-6332',365,NULL,'used',11,'2026-06-18 08:45:08','2026-06-18 08:39:47'),(24,'5201-237B-0A9B',260,NULL,'used',12,'2026-06-18 16:59:32','2026-06-18 16:58:31'),(25,'C6D9-7BE4-90A0',365,NULL,'used',13,'2026-06-20 18:36:26','2026-06-20 18:35:21'),(26,'B2C1-1D28-E563',30,NULL,'unused',NULL,NULL,'2026-06-22 19:09:16'),(27,'AF98-2DE4-EC12',30,NULL,'unused',NULL,NULL,'2026-06-22 19:09:16'),(28,'A5EF-C54E-D758',30,NULL,'unused',NULL,NULL,'2026-06-22 19:09:16'),(29,'DB38-370C-9BC6',30,NULL,'unused',NULL,NULL,'2026-06-22 19:09:16'),(30,'628A-7D34-FB4A',30,NULL,'unused',NULL,NULL,'2026-06-22 19:09:16'),(31,'EFE1-51CF-893C',30,NULL,'unused',NULL,NULL,'2026-06-22 19:09:16'),(32,'E387-A05F-7637',30,NULL,'unused',NULL,NULL,'2026-06-22 19:09:16'),(33,'35E2-96E8-CF1D',30,NULL,'unused',NULL,NULL,'2026-06-22 19:09:16'),(34,'ABBE-8961-6139',30,NULL,'unused',NULL,NULL,'2026-06-22 19:09:16'),(35,'C9A4-C8D0-C8CD',30,NULL,'unused',NULL,NULL,'2026-06-22 19:09:16'),(36,'04A2-FAF2-34BF',365,NULL,'used',14,'2026-06-22 19:12:20','2026-06-22 19:09:24');
/*!40000 ALTER TABLE `cards` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `setting_key` varchar(80) NOT NULL,
  `setting_value` mediumtext,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES ('download_app_desc','推荐使用Flclash\r\n旧版Clash不支持本站节点','2026-06-17 04:10:00'),('download_app_name','FLClash Verge','2026-06-17 04:10:00'),('download_block_enabled','1','2026-06-17 04:10:00'),('download_icon_url','https://www.mcxk.cc/wp-content/uploads/2026/06/IMG_1138.jpeg','2026-06-17 04:10:00'),('download_url','https://pan.mcxk.cc/Scientific%20Internet','2026-06-17 04:10:00'),('notice_popup_enabled','1','2026-06-17 04:10:00'),('notice_popup_once','0','2026-06-16 00:07:52'),('site_notice','<div style=\"line-height:1.8;font-size:14px;color:#333;\">\r\n  ✅ 本订阅仅限个人使用，请勿转发、分享或公开传播订阅链接。<br>\r\n  ✅ 节点资源有限，如发现多人共用、异常流量或恶意滥用行为，将永久封禁订阅。<br>\r\n  ✅ 请遵守所在地法律法规，仅用于学习交流、信息查询等合法用途。<br>\r\n  ✅ 节点为共享资源，线路可能根据实际情况进行维护、更新或替换。<br>\r\n  ✅ 请勿使用节点进行攻击、扫描、爬虫、大流量下载等高风险行为。\r\n</div>','2026-06-17 04:10:00');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subscriptions`
--

DROP TABLE IF EXISTS `subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subscriptions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `slug` varchar(140) NOT NULL,
  `url` text NOT NULL,
  `status` enum('active','disabled') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscriptions`
--

LOCK TABLES `subscriptions` WRITE;
/*!40000 ALTER TABLE `subscriptions` DISABLE KEYS */;
INSERT INTO `subscriptions` VALUES (4,'【CF优选节点】部分设备可用','cf-7b90ea','https://one.mcxk.cc/sub/clash-cf.yml','active','2026-06-17 03:41:38','2026-06-17 03:41:38'),(5,'【Zero付费专属订阅】','zero-f2f4f3','https://one.mcxk.cc/sub/clash-ktm.yml','active','2026-06-17 03:43:43','2026-06-17 03:43:43');
/*!40000 ALTER TABLE `subscriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(60) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `card_id` int(10) unsigned NOT NULL,
  `expire_time` datetime NOT NULL,
  `status` enum('active','banned') NOT NULL DEFAULT 'active',
  `token` varchar(96) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_users_token` (`token`),
  KEY `idx_users_status` (`status`),
  KEY `fk_users_card` (`card_id`),
  CONSTRAINT `fk_users_card` FOREIGN KEY (`card_id`) REFERENCES `cards` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'598648313','$2y$10$Zr1In1JsxX0.8QZAhTAtoebbBr7H5TT4F2F0wYLKf0LVoNOpt5F.e',1,'2026-07-15 23:15:54','active','4bc34ff8d32fecfbe1b836f876e24adcd730b39f7072fe8b','2026-06-15 23:15:54'),(2,'78013314','$2y$10$XunORzLCUt62FcervqQbLOwm6maSGw6zT4W9rlGKmj729kh1.Wk9S',2,'2026-07-16 00:04:01','active','c1cc5e42554d9a740f28f9812bc71f8fac3c92d4ff52db74','2026-06-16 00:04:01'),(3,'1214081005','$2y$10$jmmlfaKf7Fyv4xYuNfmr6Odzo/3Pr8johhHXxvANVEAwOt3tyWPS.',10,'2027-06-16 07:12:07','active','978ab21dd690bdef51902df53ece9a90656a63671a401d27','2026-06-16 07:12:07'),(4,'19355768305','$2y$10$GzW7Sp10Ji31fz174cmt..uTPjShFrHf7z44MWN/h1t/vXdUc8Vh.',11,'2029-03-12 18:37:20','active','a2fde86be1fea868f9daccf48103cc4e2e03d2caa15d1e5a','2026-06-16 18:37:20'),(5,'3151352189','$2y$10$vwX0payjXvraCNFfiUNf3ewkTziQ/1kLSAibb5BEDysCPvYQCW3x2',17,'2027-04-24 20:57:28','active','707f535184aa0c7a7c5ec7dfa12dc0830d45bdfc7f71d9e2','2026-06-16 20:57:28'),(6,'3144816938@qq.com','$2y$10$pGfng2HF2mP2RF8S586xnOToB3Bwhy6GhYW5jEzB43RNJclFwdor2',16,'2027-07-16 21:05:35','active','481452e088c61ccbbab944f76c9958f8a57304ee3f4991c7','2026-06-16 21:05:35'),(7,'wisteria','$2y$10$vMm3rcn2viUe48M7cdcblOKqy.ROxFGZPvNjpmwqq1MllLsUFpC3K',19,'2026-07-16 23:47:02','active','569d49cb153030bc419e39c5cf2a1f88181e4d4f92330257','2026-06-16 23:47:02'),(8,'ma2731365005','$2y$10$Iu/FfCBx4yFU9VG7.F29V.yMgJW0ZEGsIyURCsiuMYoyNBnu4AB9.',20,'2027-06-17 02:10:56','active','0b603b89b4ba3b00a5cc1e66d6d3d721317a55d63ead8590','2026-06-17 02:10:56'),(9,'li77889944','$2y$10$AYz21Vbjvx2hLMV5AVnoNuAxcHbRBM2D4cqUzJ2RRplhc/ICpxdXW',12,'2029-03-13 22:54:43','active','e3e925a338a303794e94ee3cc9ececa3e1f9fb57f11874d6','2026-06-17 22:54:43'),(10,'dingding1234','$2y$10$p0LMhNfUtiEJgC4odZXRce18gycRjWVXD47KlK4RQUIiPGNspqrDm',22,'2027-06-18 02:16:55','active','7f5faa2b51f80b337d6c10c174849ddf74e427f088fb75f8','2026-06-18 02:16:55'),(11,'304514763','$2y$10$baIVswHPUruLBWOgR04grOOz2Lz.KWC180DsSYloQ2PYw7dPV97Iu',23,'2027-06-18 08:45:08','active','967bac852be455dd4794b527bc613e54e97a2093d49e9abe','2026-06-18 08:45:08'),(12,'7863705','$2y$10$D8B8KqROzpKm69mJBLG/pOVU8GEmEgwZ7dwTfWLxGl2BvES/X4yMu',24,'2027-03-05 16:59:32','active','de0eb2f0e61e868aed92d84eef7096ed95dcb8fb4da8844b','2026-06-18 16:59:32'),(13,'dagutouteng','$2y$10$CIIKvsYeWJFC2o84WIG1O.bqjojTRHnD5zy.Tw3dYPqz5injbX6.i',25,'2027-06-20 18:36:26','active','ee482862925d79b1a54a54089bee4108ff09c9191eda1da9','2026-06-20 18:36:26'),(14,'15093246909','$2y$10$BlirK.zJrOD6.nZThPjSgOP.5BDNXHt/mvcRkibPEQtQWn/wNOa0O',36,'2027-06-22 19:12:20','active','0b96753b12313d193935629711022ee98ba2b971be3945b0','2026-06-22 19:12:20');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'clash'
--

--
-- Dumping routines for database 'clash'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-06-25  6:22:19
