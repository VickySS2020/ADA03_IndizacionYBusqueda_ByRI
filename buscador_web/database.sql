-- Ejecuta este archivo sobre la base de datos buscador_sql.
USE buscador_sql;

-- Columnas adicionales requeridas por el módulo:
-- Vocabulario.total_frecuencias: suma de las frecuencias del término en todos los documentos.
-- Posting.fragmento: fragmento de hasta 50 caracteres asociado al posting.
ALTER TABLE Vocabulario
    ADD COLUMN total_frecuencias INT NOT NULL DEFAULT 0;

ALTER TABLE Posting
    ADD COLUMN fragmento VARCHAR(50) NOT NULL DEFAULT '';
