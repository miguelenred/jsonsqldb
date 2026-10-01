/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.14-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: fuente
-- ------------------------------------------------------
-- Server version	10.11.14-MariaDB-0ubuntu0.24.04.1

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
-- Table structure for table `a_pedidos`
--

DROP TABLE IF EXISTS `a_pedidos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `a_pedidos` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `cliente_id` int(11) unsigned NOT NULL,
  `total` decimal(12,2) DEFAULT NULL,
  `creado` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_cliente` (`cliente_id`),
  CONSTRAINT `fk_pedidos_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `z_clientes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `a_pedidos`
--

LOCK TABLES `a_pedidos` WRITE;
/*!40000 ALTER TABLE `a_pedidos` DISABLE KEYS */;
INSERT INTO `a_pedidos` VALUES
(1,10,120.50,'2026-05-01 10:00:00'),
(2,11,0.10,'2026-05-02 11:00:00'),
(3,10,NULL,'2026-05-03 12:00:00');
/*!40000 ALTER TABLE `a_pedidos` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`prueba`@`localhost`*/ /*!50003 TRIGGER trg_pedidos BEFORE INSERT ON a_pedidos FOR EACH ROW BEGIN IF NEW.total < 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'negativo'; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Temporary table structure for view `v_grandes`
--

DROP TABLE IF EXISTS `v_grandes`;
/*!50001 DROP VIEW IF EXISTS `v_grandes`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_grandes` AS SELECT
 1 AS `id`,
  1 AS `total` */;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `z_clientes`
--

DROP TABLE IF EXISTS `z_clientes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `z_clientes` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(120) NOT NULL COMMENT 'correo',
  `nombre` varchar(80) DEFAULT NULL,
  `saldo` decimal(10,2) NOT NULL DEFAULT 0.00,
  `ratio` double DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `tipo` enum('particular','empresa') DEFAULT 'particular',
  `notas` text DEFAULT NULL,
  `alta` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`),
  KEY `ix_nombre` (`nombre`(20)),
  CONSTRAINT `ck_saldo` CHECK (`saldo` > -100000)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `z_clientes`
--

LOCK TABLES `z_clientes` WRITE;
/*!40000 ALTER TABLE `z_clientes` DISABLE KEYS */;
INSERT INTO `z_clientes` VALUES
(10,'ana@e.es','Ana O\'Brien',10.50,0.333333333333333,1,'particular','línea 1\nlínea 2','2026-01-02 03:04:05'),
(11,'bea@e.es','Bea \"comillas\" \\ barra',-3.00,NULL,0,'empresa',NULL,'2026-02-03 04:05:06'),
(12,'cris@e.es','Cris 😀 emoji',0.00,1e20,1,NULL,'tab	here\r\nCRLF','2026-03-04 05:06:07'),
(13,'dani@e.es',NULL,99999.99,-2.5,1,'particular','%_ comodines y ;punto y coma','2026-04-05 06:07:08');
/*!40000 ALTER TABLE `z_clientes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'fuente'
--

--
-- Final view structure for view `v_grandes`
--

/*!50001 DROP VIEW IF EXISTS `v_grandes`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`prueba`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_grandes` AS select `a_pedidos`.`id` AS `id`,`a_pedidos`.`total` AS `total` from `a_pedidos` where `a_pedidos`.`total` > 100 */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed
