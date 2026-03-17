RAG Chat – PBTechnologies

Sistema de chat inteligente basado en RAG (Retrieval Augmented Generation) que responde preguntas utilizando información extraída automáticamente del catálogo de productos de PBTechnologies.

El sistema recopila productos desde el sitio web usando Firecrawl, almacena la información en una base de conocimiento y permite consultar esos datos mediante un chat conversacional.

Arquitectura del Sistema

El flujo del sistema funciona de la siguiente manera:

Extracción de datos

Firecrawl rastrea el sitio web de productos.

Obtiene las URLs de cada producto.

Procesamiento

Se extrae la información relevante de cada página:

Nombre

Descripción

Características

URL

Base de conocimiento

Los productos se almacenan como documentos para el sistema RAG.

Consulta mediante chat

El usuario realiza una pregunta.

El sistema busca información relevante en la base de conocimiento.

El modelo genera una respuesta basada en esos datos.
