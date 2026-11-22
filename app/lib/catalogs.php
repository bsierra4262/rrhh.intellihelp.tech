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
 * Áreas y puestos iniciales: se cargan una sola vez en la base y después se administran desde
 * Administración → Áreas y puestos. Las áreas siguen los grupos del Clasificador Nacional de
 * Ocupaciones del INDEC (CNO-17) y los puestos, las ocupaciones de la CIUO-08 de la OIT con los
 * nombres que se usan en los avisos del mercado laboral argentino.
 */
const AREAS_SEED = [
    'Dirección' => ['Director', 'Presidente', 'Vicepresidente', 'Socio gerente', 'Gerente general', 'Director ejecutivo (CEO)', 'Director de operaciones (COO)', 'Director de tecnología (CTO)', 'Director financiero (CFO)', 'Director comercial', 'Asistente de dirección', 'Secretario ejecutivo'],
    'Operaciones' => ['Gerente de Operaciones', 'Jefe de Operaciones', 'Coordinador de servicio', 'Service Delivery Manager', 'Responsable de cuenta (outsourcing)', 'Coordinador de operaciones', 'Supervisor de operaciones', 'Analista de operaciones', 'Analista de planificación de servicios', 'Analista de SLA y reportes', 'Administrador de contratos de servicio', 'Gestor de servicios TI (ITSM)', 'Analista de procesos', 'Técnico residente en cliente', 'Asistente de operaciones'],
    'Administración' => ['Gerente de Administración y Finanzas', 'Jefe de Administración', 'Responsable administrativo', 'Administrativo', 'Auxiliar administrativo', 'Analista administrativo', 'Analista de cuentas a pagar', 'Analista de cuentas a cobrar', 'Analista de facturación', 'Analista de cobranzas', 'Gestor de cobranzas', 'Recepcionista', 'Telefonista', 'Cadete', 'Data entry'],
    'Contabilidad' => ['Contador general', 'Jefe de Contabilidad', 'Contador', 'Analista contable', 'Auxiliar contable', 'Analista de impuestos', 'Liquidador de impuestos', 'Analista de costos', 'Auditor interno', 'Analista de control de gestión'],
    'Finanzas y Tesorería' => ['Gerente de Finanzas', 'Jefe de Tesorería', 'Tesorero', 'Analista de tesorería', 'Analista financiero', 'Analista de presupuesto', 'Analista de créditos', 'Cajero', 'Auxiliar de tesorería'],
    'Recursos Humanos' => ['Gerente de Recursos Humanos', 'Jefe de Recursos Humanos', 'HR Business Partner', 'Generalista de Recursos Humanos', 'Analista de Recursos Humanos', 'Analista de selección IT', 'Analista de selección', 'Recruiter IT', 'Sourcer IT', 'Analista de administración del personal', 'Liquidador de sueldos', 'Analista de liquidación de sueldos', 'Analista de compensaciones y beneficios', 'Analista de capacitación y desarrollo', 'Analista de relaciones laborales', 'Analista de clima y cultura', 'Analista de comunicaciones internas', 'Responsable de Employer Branding', 'Asistente de Recursos Humanos', 'Pasante de Recursos Humanos'],
    'Legales' => ['Gerente de Legales', 'Jefe de Legales', 'Abogado', 'Abogado laboralista', 'Abogado corporativo', 'Analista legal', 'Paralegal', 'Responsable de Compliance', 'Analista de Compliance', 'Analista de contratos', 'Procurador'],
    'Comercial' => ['Gerente Comercial', 'Jefe de Ventas', 'Key Account Manager (KAM)', 'Ejecutivo de cuentas', 'Ejecutivo comercial', 'Vendedor', 'Vendedor técnico', 'Representante de desarrollo de ventas (SDR)', 'Ejecutivo de nuevos negocios', 'Preventa', 'Ingeniero de preventa', 'Arquitecto de soluciones', 'Analista comercial', 'Analista de licitaciones', 'Administrativo comercial', 'Gerente de Canales', 'Ejecutivo de canales (Partners)', 'Customer Success Manager', 'Asistente comercial'],
    'Marketing' => ['Gerente de Marketing', 'Jefe de Marketing', 'Responsable de Marketing', 'Analista de Marketing', 'Analista de Marketing digital', 'Especialista en SEO/SEM', 'Especialista en Performance Marketing', 'Community Manager', 'Social Media Manager', 'Content Manager', 'Redactor de contenidos', 'Diseñador gráfico', 'Diseñador multimedia', 'Analista de Product Marketing', 'Responsable de comunicación institucional', 'Organizador de eventos', 'Asistente de Marketing'],
    'Compras' => ['Gerente de Compras', 'Jefe de Compras', 'Comprador', 'Comprador técnico IT', 'Analista de compras', 'Analista de proveedores', 'Administrativo de compras', 'Asistente de compras'],
    'Logística y Depósito' => ['Jefe de Logística', 'Responsable de Depósito', 'Analista de logística', 'Analista de inventario', 'Encargado de depósito', 'Operario de depósito', 'Administrativo de logística', 'Gestor de activos IT (Asset Manager)', 'Chofer', 'Repartidor'],
    'Atención al cliente' => ['Jefe de Atención al cliente', 'Supervisor de Atención al cliente', 'Team Leader de Atención al cliente', 'Representante de Atención al cliente', 'Analista de Atención al cliente', 'Operador de Contact Center', 'Analista de postventa', 'Analista de reclamos'],
    'Calidad' => ['Gerente de Calidad', 'Jefe de Calidad', 'Responsable de Calidad', 'Analista de Calidad', 'Auditor de Calidad', 'Analista de mejora continua', 'Analista de normas ISO', 'Analista de procesos y calidad'],
    'Proyectos (PMO)' => ['Gerente de Proyectos', 'Jefe de PMO', 'Project Manager', 'Project Leader', 'Líder de proyecto IT', 'Analista PMO', 'Coordinador de proyectos', 'Scrum Master', 'Agile Coach', 'Product Owner', 'Product Manager', 'Analista funcional', 'Analista de negocio (Business Analyst)', 'Asistente de proyectos'],
    'Sistemas e Infraestructura IT' => ['Gerente de Sistemas', 'Jefe de Sistemas e Infraestructura IT', 'Líder de Infraestructura', 'Administrador de sistemas (SysAdmin)', 'Administrador de sistemas Windows', 'Administrador de sistemas Linux', 'Administrador de servidores', 'Administrador de Active Directory', 'Administrador de Microsoft 365', 'Administrador de virtualización (VMware/Hyper-V)', 'Administrador de backup y almacenamiento', 'Ingeniero de infraestructura', 'Ingeniero Cloud', 'Arquitecto Cloud', 'Arquitecto de infraestructura', 'Ingeniero DevOps', 'Ingeniero SRE', 'Ingeniero de plataformas', 'Operador de centro de cómputos', 'Analista de infraestructura', 'Técnico de infraestructura', 'Analista de monitoreo', 'Administrador de ERP'],
    'Desarrollo de Software' => ['Gerente de Desarrollo', 'Jefe de Desarrollo', 'Arquitecto de software', 'Tech Lead', 'Desarrollador', 'Desarrollador Backend', 'Desarrollador Frontend', 'Desarrollador Full Stack', 'Desarrollador Mobile', 'Desarrollador .NET', 'Desarrollador Java', 'Desarrollador PHP', 'Desarrollador Python', 'Desarrollador JavaScript', 'Desarrollador de RPA', 'Programador', 'Analista programador', 'Analista de sistemas', 'Analista técnico', 'Analista QA', 'QA Automation', 'Tester', 'Diseñador UX/UI'],
    'Soporte Técnico' => ['Jefe de Soporte Técnico', 'Supervisor de la Mesa de Ayuda', 'Coordinador de Mesa de Ayuda', 'Team Leader de Mesa de Ayuda', 'Analista de Mesa de Ayuda', 'Operador de Mesa de Ayuda', 'Soporte Técnico N1', 'Soporte Técnico N2', 'Soporte Técnico N3', 'Técnico de soporte', 'Técnico de soporte en sitio', 'Técnico de soporte remoto', 'Soporte a usuarios', 'Técnico PC', 'Técnico reparador de PC y notebooks', 'Técnico de impresoras', 'Analista de soporte de aplicaciones', 'Analista de gestión de incidentes', 'Analista de gestión de cambios', 'Analista de Service Desk', 'Soporte VIP'],
    'Seguridad Informática' => ['Gerente de Seguridad de la Información (CISO)', 'Jefe de Seguridad Informática', 'Analista de Ciberseguridad', 'Analista de Seguridad Informática', 'Analista SOC', 'Ingeniero de seguridad', 'Arquitecto de seguridad', 'Pentester', 'Analista de respuesta a incidentes', 'Analista de gestión de vulnerabilidades', 'Analista de identidades y accesos (IAM)', 'Analista de riesgo tecnológico', 'Auditor de sistemas', 'Especialista DevSecOps'],
    'Datos y Analítica' => ['Jefe de Datos', 'Arquitecto de datos', 'Ingeniero de datos', 'Científico de datos', 'Analista de datos', 'Analista BI', 'Desarrollador BI', 'Administrador de bases de datos (DBA)', 'Analista de bases de datos', 'Ingeniero de Machine Learning', 'Ingeniero de IA', 'Analista de reportes'],
    'Redes y Telecomunicaciones' => ['Jefe de Redes y Telecomunicaciones', 'Arquitecto de redes', 'Ingeniero de redes', 'Administrador de redes', 'Analista de redes', 'Técnico de redes', 'Administrador de firewall', 'Ingeniero de telecomunicaciones', 'Técnico en telecomunicaciones', 'Analista de telefonía IP', 'Administrador de telefonía (PBX)', 'Operador NOC', 'Técnico de radioenlaces', 'Técnico de fibra óptica'],
    'Instalaciones y coordinación de campo' => ['Jefe de Instalaciones', 'Coordinador de campo', 'Supervisor de campo', 'Supervisor técnico', 'Despachante técnico (Dispatcher)', 'Planificador de órdenes de trabajo', 'Técnico de campo', 'Técnico instalador', 'Instalador', 'Instalador de cableado estructurado', 'Instalador de redes', 'Instalador de fibra óptica', 'Instalador de CCTV y seguridad electrónica', 'Instalador de control de acceso', 'Instalador de telefonía', 'Técnico de mantenimiento de equipos informáticos', 'Técnico electricista', 'Técnico electrónico', 'Ayudante técnico'],
    'Servicios Generales y Mantenimiento' => ['Jefe de Servicios Generales', 'Encargado de mantenimiento', 'Técnico de mantenimiento edilicio', 'Electricista', 'Ordenanza', 'Personal de limpieza', 'Mensajero', 'Encargado de seguridad patrimonial'],
    'Higiene, Seguridad y Medio Ambiente' => ['Jefe de Higiene y Seguridad', 'Responsable de Higiene y Seguridad', 'Licenciado en Higiene y Seguridad', 'Técnico en Higiene y Seguridad', 'Coordinador HSE', 'Analista de Medio Ambiente'],
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
 * Áreas con sus puestos, ordenadas alfabéticamente.
 *
 * @return array<string, string[]>
 */
function catalog_areas(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (rows('SELECT a.name AS area, p.name AS pos FROM areas a LEFT JOIN positions p ON p.area_id = a.id ORDER BY a.name, p.name') as $r) {
            $cache[$r['area']] ??= [];
            if ($r['pos'] !== null) {
                $cache[$r['area']][] = $r['pos'];
            }
        }
    }

    return $cache;
}

/**
 * Carga áreas y puestos en la base (sin duplicar los existentes).
 *
 * @param array<string, string[]> $catalog
 * @return int Puestos agregados.
 */
function catalog_import(array $catalog): int
{
    $n = 0;
    foreach ($catalog as $area => $positions) {
        q('INSERT IGNORE INTO areas (name) VALUES (?)', [$area]);
        $aid = (int) val('SELECT id FROM areas WHERE name = ?', [$area]);
        foreach ($positions as $p) {
            $n += q('INSERT IGNORE INTO positions (area_id, name) VALUES (?, ?)', [$aid, $p])->rowCount();
        }
    }

    return $n;
}

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
