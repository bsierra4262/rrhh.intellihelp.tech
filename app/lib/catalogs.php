<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Catálogos de la ficha de cada persona: áreas, puestos, convenios colectivos y categorías.
 *
 * La ley no fija áreas ni nombres de puestos: los de este catálogo siguen la descripción de
 * puestos de la empresa (carpeta ORGANIZACIÓN de RRHH). Lo que sí es oficial es el convenio
 * colectivo y la categoría que corresponde dentro de él. En todos los casos se puede elegir
 * «Otro» y escribirlo a mano.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/**
 * Áreas de la empresa y los puestos de cada una.
 */
const AREAS = [
    'Dirección' => ['Director', 'Presidente', 'Socio gerente'],
    'Operaciones' => ['Gerente de Operaciones', 'Coordinador de servicio', 'Supervisor de operaciones'],
    'Administración' => ['Responsable de Administración', 'Administrativo', 'Administrativo semi senior', 'Analista contable', 'Tesorero'],
    'Recursos Humanos' => ['Responsable de Recursos Humanos', 'Analista de Recursos Humanos', 'Analista de administración del personal', 'Analista de selección IT'],
    'Comercial' => ['Gerente Comercial', 'Ejecutivo de cuentas', 'Preventa', 'Vendedor'],
    'Sistemas e Infraestructura IT' => ['Jefe de Sistemas e Infraestructura IT', 'Administrador de sistemas (SysAdmin)', 'Administrador de redes', 'Analista de seguridad informática', 'Desarrollador', 'Analista funcional'],
    'Soporte Técnico' => ['Supervisor de la Mesa de Ayuda', 'Soporte Técnico N1', 'Soporte Técnico N2', 'Soporte Técnico N3', 'Analista de Mesa de Ayuda'],
    'Instalaciones y coordinación de campo' => ['Coordinador de campo', 'Técnico de campo', 'Instalador'],
    'Marketing' => ['Responsable de Marketing', 'Analista de Marketing', 'Diseñador gráfico', 'Community manager'],
];

/**
 * Convenios colectivos de trabajo nacionales de actividad (número oficial => actividad), verificados
 * en el buscador de convenios del Ministerio de Capital Humano
 * (https://convenios.trabajo.gob.ar/ConsultaWeb/consultaBasica.asp). No hay un listado oficial
 * descargable de todos: cualquier otro se carga con su número oficial. La informática no tiene un
 * convenio propio de actividad.
 */
const CONVENIOS = [
    '' => 'Fuera de convenio (solo LCT)',
    '130/75' => 'Empleados de Comercio',
    '781/20' => 'Comercio — Contactos para terceros (call center)',
    '420/05' => 'Aceiteros',
    '574/10' => 'Acopio de granos',
    '57/89' => 'Agencias de publicidad',
    '547/08' => 'Agencias de Viajes y Turismo',
    '18/75' => 'Bancarios',
    '40/89' => 'Camioneros — Transporte de cargas y logística',
    '401/05' => 'Comedores y refrigerios (catering)',
    '76/75' => 'Construcción',
    '589/10' => 'Encargados de edificios',
    '736/16' => 'Entidades deportivas y civiles',
    '371/03' => 'Estaciones de servicio',
    '409/05' => 'Gráficos (FATIDA)',
    '60/89' => 'Gráficos (Sindicato Gráfico Argentino)',
    '389/04' => 'Hoteleros y Gastronómicos',
    '362/03' => 'Hoteles de turismo',
    '244/94' => 'Industria de la alimentación',
    '56/75' => 'Industria de la carne',
    '2/88' => 'Industria lechera',
    '77/89' => 'Industrias químicas y petroquímicas',
    '352/02' => 'Locutorios',
    '36/75' => 'Luz y Fuerza',
    '335/75' => 'Madereros',
    '281/96' => 'Maestranza — Empresas de limpieza',
    '722/15' => 'Mensajeros motociclistas',
    '260/75' => 'Metalúrgicos',
    '66/89' => 'Molineros',
    '88/90' => 'No docentes de la enseñanza privada (SAEOEP)',
    '318/99' => 'No docentes de la enseñanza privada (SOEME)',
    '396/04' => 'Petroleros privados',
    '797/22' => 'Plásticos',
    '301/75' => 'Prensa escrita, radial y televisiva',
    '541/08' => 'Prensa gráfica',
    '122/75' => 'Sanidad — Clínicas, sanatorios y geriátricos',
    '120/75' => 'Sanidad — Droguerías',
    '459/06' => 'Sanidad — Emergencias médicas y medicina domiciliaria',
    '103/75' => 'Sanidad — Hospitales de beneficencia y mutualistas',
    '42/89' => 'Sanidad — Industria (laboratorios)',
    '108/75' => 'Sanidad — Institutos de diagnóstico sin internación',
    '107/75' => 'Sanidad — Mutualidades',
    '507/07' => 'Seguridad y Vigilancia',
    '264/95' => 'Seguros',
    '283/97' => 'Seguros — Aseguradoras de vida',
    '27/88' => 'Talleres de reparación de automotores',
    '436/06' => 'Taxis (CABA)',
    '713/15' => 'Telecomunicaciones — Telefonía celular y móviles (FATEL)',
    '712/15' => 'Telecomunicaciones — Telefonía celular y móviles (FOEESITRA)',
    '676/13' => 'Telecomunicaciones — Telefonía celular y móviles (FOETRA Buenos Aires)',
    '715/15' => 'Telecomunicaciones — Telefonía móvil, mandos medios',
    '714/15' => 'Telecomunicaciones — Telefonía móvil, personal jerárquico',
    '201/92' => 'Telefónicos',
    '500/07' => 'Textiles',
    '460/73' => 'Transporte automotor de pasajeros',
    '204/93' => 'Vestido — Confección de indumentaria',
    '308/75' => 'Viajantes de Comercio',
    '85/89' => 'Vitivinícolas',
];

/**
 * Categorías oficiales de cada convenio. CCT 130/75: arts. 5 a 10 y escala FAECYS de octubre de 2026.
 */
const CATEGORIAS = [
    '130/75' => ['Maestranza A', 'Maestranza B', 'Maestranza C', 'Administrativo A', 'Administrativo B', 'Administrativo C', 'Administrativo D', 'Administrativo E', 'Administrativo F', 'Cajeros A', 'Cajeros B', 'Cajeros C', 'Auxiliar A', 'Auxiliar B', 'Auxiliar C', 'Auxiliar Especializado A', 'Auxiliar Especializado B', 'Vendedor A', 'Vendedor B', 'Vendedor C', 'Vendedor D'],
];

/**
 * Buscador oficial de convenios colectivos.
 */
const CONVENIOS_BUSCADOR = 'https://convenios.trabajo.gob.ar/ConsultaWeb/consultaBasica.asp';

/**
 * Nombre del convenio para mostrar: número y actividad.
 */
function convenio_label(?string $cct): string
{
    $cct = (string) $cct;
    if ($cct === '') {
        return CONVENIOS[''];
    }

    return isset(CONVENIOS[$cct]) ? "CCT $cct — " . CONVENIOS[$cct] : "CCT $cct";
}

/**
 * Valor elegido en un campo con lista y opción «Otro» escrita a mano.
 */
function catalog_value(string $field, int $max = 120): ?string
{
    $v = post($field);
    if ($v === '__otro') {
        $v = post($field . '_otro');
    }
    $v = preg_replace('/\s+/u', ' ', trim((string) $v));

    return $v === '' ? null : mb_substr($v, 0, $max);
}
